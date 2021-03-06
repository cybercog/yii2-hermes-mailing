<?php
/**
 * @link https://github.com/thinker-g/yii2-hermes-mailing
 * @copyright Copyright (c) Thinker_g (Jiyan.guo@gmail.com)
 * @license MIT
 * @version v1.0.0
 * @author Thinker_g
 */

namespace thinkerg\HermesMailing\console;

use Yii;
use yii\console\Controller;
use yii\helpers\Console;
use yii\base\Exception;
use yii\mail\MailEvent;

/**
 * Hermes Mailing's main command controller.
 * When this starts up, it will firstly call [[signMails()]] to update signature column of the table
 * specified by [[modelClass]]. Each process has a unique sigature, so once a mail entry is signed by one
 * process it won't be taken by any other process. Then we can startup mailer processes parallely. 
 * The number of entries signed each time is specified by option [[signSize]].
 *
 * After the signing, the process will then fetch its signed mails in chunk, the number fetched each time
 * is specified by the option [[pageSize]], this parameter is use to controller the memory usage
 * of a single process.
 *
 * After a chunk of mail is fetched from database, it will call the "mailer" component to send them one by one.
 *
 * For better extendability, the DB connection is returned from the instance of [[modelClass]], instead of
 * using Yii::$app->getDb(). If in some cases, the emails are stored in another database than the primary one,
 * people just need to override the getDb() method of the [[modelClass]].
 *
 * One special case is that the "installerMode": As the modelClass, which extends [[\yii\db\ActiveRecord]], is
 * generated while installing this extension. We cannot return a db connection from a model class that doesn't
 * exist. So we use the attribute [[\thinkerg\HermesMailing\installer\Migration::db]] to get the db connection,
 * and that attribute configurable during the installation, and its default value is the 'db' component
 * of Yii::$app. If migration's [[db]] component is customized, the getDb() method of the generated AR model
 * will have to be overridden, to connect to the db in which the data table is created.
 *
 * @since v1.0.0
 * @author Thinker_g
 *
 * @property \yii\db\ActiveRecord $fetchedMail The mail AR retrieved from database and to be sent.
 */
class DefaultController extends Controller
{

    /**
     * Event triggered before compose mail message.
     */
    const EVENT_BEFORE_COMPOSE_MSG = 'hermesEventBeforeComposeMsg';

    /**
     * Status value of NEVER SENT.
     */
    const ST_NEVER = null;

    /**
     * Status value of FAILED. FAILED email will never be resent again.
     */
    const ST_FAILED = 'failed';

    /**
     * Status value of RETRY. RETRY email will be tried to resend until reach the EmailQueue::$retry_time.
     */
    const ST_RETRY = 'retry';

    /**
     * Status value of SUCCEED.
     */
    const ST_SUCCEED = 'succeed';

    /**
     * Email AR model name.
     * Make sure the model class is imported after installation.
     * LEAVE THIS NULL, unless the model name is not generated by the table name.
     * If this is set, table name will be retrieved by "tableName()" of its instance.
     * This is for using some already existed AR. Won't be needed for default installation.
     * @var string
     */
    public $modelClass = 'app\\models\\HermesMail';

    /**
     * Column (attribute name) of email table to store process signature.
     * This is mandatory attribute to the AR model. There must be a column to play this role.
     * Default to "signature".
     * @var string
     */
    public $signatureAttr = 'signature'; // mandatory  column

    /**
     * Column (attribute name) of email table to store sending status.
     * This is mandatory attribute to the AR model. There must be a column to play this role.
     * Default to "status".
     * @var string
     */
    public $statusAttr = 'status'; // mandatory  column

    /**
     * Column (attribute name) of email table to store retry times.
     * Optional attribute, will only take effects when it exists in the model.
     * Default to "retry_times".
     * @var string
     */
    public $retryAttr = 'retry_times';

    /**
     * Column (attribute name) of email table to assign emails to be send by the processes from specific server.
     * Optional attribute, will only take effects when it exists in the model.
     * Default to "assigned_to_svr".
     * @var string
     */
    public $assignedToSvrAttr = 'assigned_to_svr';

    /**
     * Mailer adaptor, can be string or configuration array.
     * If this is string, program will firstly try to find application component of this id.
     * If failed program will try to use it as a class name.
     *
     * @var string | array
     */
    public $mailer = 'mailer';

    /**
     * Array to map AR model attributes to Mail Message attributes.
     * 'arAttr' => 'messageAttr', where 'arAttr' is the attribute of AR,
     * and 'messageAttr' is the attribute of message.
     * Value set in 'messageAttr' can be any public attribute of Message object,
     * if the attribute doesn't exist, it might be implemented by a setter,
     * which is invoked by overwriting magic method __set().
     * @var array
     */
    public $attrMap = [
        'charset' => 'charset',
        'from' => 'from',
        'to' => 'to',
        'reply_to' => 'replyTo',
        'cc' => 'cc',
        'bcc' => 'bcc',
        'subject' => 'subject',
        'html_body' => 'htmlBody'
    ];

    /**
     * Behavior to record the server ID of current process into emails attribute,
     * after the mail is sent by the process.
     * The attribute is specified by the "sentByAttr" attribute of this behavior object.
     * And default "sentByAttr" is "sent_by".
     * @var string|array
     */
    public $sentByBehavior = 'thinkerg\HermesMailing\components\SentByBehavior';

    /******************** Begin command options *******************/

    /**
     * Whether to enable test mode. If set to ture, command will call testSend() method of the mailer adaptor.
     * Default to false.
     * @var bool
     */
    public $testMode = false;

    /**
     * Server id of processes start on CURRENT SERVER. The process will retrieve emails assigned to THIS server id in central database.
     * When this is distributed on different servers, make sure this is unique among all servers which is connecting to the same central db.
     * Default to 0.
     * @var int
     */
    public $serverID = 0;

    /**
     * When this number of mails are sent, the program will shutdown after current signed mails are all processed.
     * @var int
     */
    public $maxSent = null;

    /**
     * How many emails to sign each time.
     * Default to 100.
     * @var int
     */
    public $signSize = 100;

    /**
     * How many signed emails are loaded to memory each time.
     * Default to 50.
     * @var int
     */
    public $pageSize = 50;

    /**
     * How many times to resend after first send is failed.
     * Only take effects when column specified by EmailQueueCommand::$retryCol exists.
     * Default to 0.
     * @var int
     */
    public $retryTimes = 0;

    /**
     * Whether to sign rows whose "assignedToSvr" attribute equals to $this->serverId.
     * @var bool
     */
    public $signUnassigned = true;

    /**
     * Whether to renew sigature every time when we sign the mail entries.
     * @var bool
     */
    public $renewSignature = false;

    /**
     * Spam rules for CURRENT PROCESS, each element in format m=>n, where m is the SENT_COUNT n is the SECOND.
     * This means pause n seconds after every m mails sent out.
     * When a larger "m" value is times of smaller "m", only the largest "m" rule is applied, all smaller "m" rules are ignored.
     * @example array(
     *     500 => 10,
     *     1000 => 30
     * ); // Pause 10 secs after 500 emails sent out, pause 30 secs every 1000 emails sent out.
     * @var array
     */
    public $spamRules;

    /**
     * Set to true to use "applySpamRulesLite()" or false to user "applySpamRules()"
     * to apply anti spam rules.
     * @var bool
     */
    public $useLiteAntiSpams = false;

    /******************** End command options *******************/

    /*
     * ============================================================
     *
     * Following private attributes are all runtime helpers.
     *
     * ============================================================
     */
    /**
     * Instance of \yii\db\Connection used.
     * Retrieved from getDb() for mail model.
     * @var \yii\db\Connection
     */
    private $_db;

    /**
     * Instance of mailer adaptor specified by self::$mailer.
     * @var \yii\mail\MailerInterface
     */
    private $_mailer;

    /**
     * Emailing counter of current process.
     * @var int
     */
    private $_sentCount = 0;

    /**
     * Signature of current process.
     * @var string
     */
    private $_signature;

    /**
     * Template model of mail AR.
     * Returned by invoking Yii::createObject($this->modelClass);
     * Will be populated in initialization phase.
     * @var \yii\db\ActiveRecord
     */
    private $_templateModel;

    /**
     * Used by method DefaultController::applySpamRules();
     * DON'T MANUALLY MODIFY IT.
     * @var array
     */
    private $_nextStop;

    /**
     * The mail AR retrieved from database and to be sent.
     * @var \yii\db\ActiveRecord
     */
    private $_fetchedMail;

    /*
     * ============================================================
     *
     * Attributes below are for installion, and might be used at most for once.
     * If you are using your own Email AR Model, please do not touch this part.
     *
     * ============================================================
     */
    /**
     * Enable installer mode or not.
     * @var bool
     */
    public $installerMode = false;

    public $installerActions = [
        'install' => 'thinkerg\HermesMailing\installer\actions\InstallAction',
        'uninstall' => 'thinkerg\HermesMailing\installer\actions\UninstallAction',
        'fill4test' => 'thinkerg\HermesMailing\installer\actions\Fill4TestAction',
        'determine-anti-spams' => 'thinkerg\HermesMailing\installer\actions\DetermineAntiSpamsAction'
    ];

    /**
     * @overriding
     * @see \yii\base\Controller::actions()
     */
    public function actions()
    {
        $actions = parent::actions();
        if ($this->installerMode) {
            $actions = array_merge($actions, $this->installerActions);
        }
        return $actions;
    }

    /**
     * Display this help message.
     * @return number
     */
    public function actionIndex()
    {
        $this->run("/help", [$this->id]);
        return 0;
    }

    /**
     * Send email queue. This can be ran in multi-process mode.
     */
    public function actionSendQueue()
    {
        $this->consoleLog("Emailing process (server id: {$this->serverID}) started.", true, console::FG_GREEN);
        while ($signedNum = $this->signEmails($this->signUnassigned, $this->renewSignature)) {
            $this->consoleLog("Signed $signedNum entries with signature: $this->_signature.");
            $this->sendSigned();
            $this->consoleLog("{$signedNum} emails processed by signature: {$this->signature}.");
            if (!empty($this->maxSent) && ($this->_sentCount >= $this->maxSent)) {
                $this->consoleLog("Max sent limit ({$this->maxSent}) reached, shutting down.");
                break;
            }
        }
        $this->consoleLog("Emailing process (server id: {$this->serverID}) stopped.", true, console::FG_GREEN);
        return 0;
    }

    /**
     * Sign emails to claim emails send by CURRENT PROCESS, then signed emails will not be retrieved by other processes.
     *
     * @param bool $signUnassigned Whether to sign email those are not assigned to any server_id (IS NULL).
     * Take effects only when column specified by EmailQueueCommand::$sendByCol exists.
     * @param bool $renewSignature Whether to renew the signature when sign emails every time.
     *
     * @return int The numbers of mails signed this time.
     *
     */
    protected function signEmails($signUnassigned = true, $renewSignature = false)
    {
        $modelClass = $this->_templateModel;
        $where = [$this->signatureAttr => null];
        if ($modelClass->hasAttribute($this->assignedToSvrAttr)) {
            $where = ['and', $where];
            if ($signUnassigned) {
                $where[] = ['or', [$this->assignedToSvrAttr => $this->serverID], [$this->assignedToSvrAttr => null]];
            } else {
                $where[] = [$this->assignedToSvrAttr => $this->serverID];
            }
        } elseif (!$signUnassigned) {
            $msg = "Cannot find any entries to sign, while 'signUnassigned' is false ";
            $msg .= "and attribute '{$this->assignedToSvrAttr}' is missing in model class.";
            $this->consoleLog($msg, true, Console::FG_YELLOW);
            return 0;
        }
        $cols = [$this->signatureAttr => $this->getSignature($renewSignature)];
        $queryBuilder = $this->_db->getQueryBuilder();
        $sql = $queryBuilder->update($modelClass::tableName(), $cols, $where, $params);
        if ($this->signSize) {
            $sql = $queryBuilder->buildOrderByAndLimit($sql, null, $this->signSize, null);
        }

        return $this->_db->createCommand($sql, $params)->execute();
    }

    /**
     * Send currently signed emails.
     */
    protected function sendSigned()
    {
        while ($fetchedMails = $this->findMailBySignature($this->pageSize)) {
            foreach($fetchedMails as $this->_fetchedMail) {
                if ($this->sentByBehavior) {
                    $this->_fetchedMail->attachBehavior(
                        $this->sentByBehavior->className(),
                        $this->sentByBehavior
                    );
                }
                $msg = $this->assembleMailMessage($this->_fetchedMail);
                $isSent = $this->testMode ? rand(0,1) : $this->getMailer()->send($msg);
                $this->processEmailStatus($this->_fetchedMail, $isSent);
                $this->_fetchedMail->save(false);
                $this->{
                    $this->useLiteAntiSpams ? "applySpamRulesLite" : "applySpamRules"
                }(++$this->_sentCount);
            }
        }
    }

    /**
     * Assemble ang return mail message using method compose() of specified Mailer object.
     * @param \yii\db\ActiveRecord $mail
     * @return \yii\mail\MessageInterface
     */
    protected function assembleMailMessage(\yii\db\ActiveRecord &$mail)
    {
        $message = $this->getMailer()->compose();
        $this->trigger(self::EVENT_BEFORE_COMPOSE_MSG, new MailEvent(['message' => $message]));
        foreach ($this->attrMap as $arAttr => $msgAttr) {
            if (isset($mail->attributes[$arAttr])) {
                $message->{$msgAttr} = $mail->{$arAttr};
            }
        }
        return $message;
    }

    /**
     * Fetch mail ARs with signature and additional conditions.
     * @param string $limit
     * @param string $where
     * @return array Array of Email AR.
     */
    protected function findMailBySignature($limit, $where = null)
    {
        $condition = ['and',[$this->signatureAttr => $this->_signature]];
        is_null($where) && $where = ['or',
            [$this->statusAttr => self::ST_NEVER],
            [$this->statusAttr => self::ST_RETRY]
        ];
        $condition[] = $where;
        $tblModel = $this->_templateModel;

        return $tblModel::find()
            ->where($condition)
            ->limit($limit)
            ->all();
    }

    /**
     * Determine status of one email AR. Be called after every email sent.
     * Result statuc can be "succeed", "failed" or "retry".
     * @param CActiveRecord $ar
     * @param bool $isSent
     */
    protected function processEmailStatus(\yii\db\ActiveRecord &$mail, $isSent)
    {
        if (! ($mail->hasAttribute($this->retryAttr) && $this->retryTimes > 0)) {
            $mail->{$this->statusAttr} = $isSent ? self::ST_SUCCEED : self::ST_FAILED;
            return;
        }

        if ($isSent) {
            if ($mail->{$this->statusAttr} == self::ST_RETRY) {
                // Current is a retry sending.
                $mail->{$this->retryAttr} ++;
            }
            $mail->{$this->statusAttr} = self::ST_SUCCEED;
        } else {
            if ($mail->{$this->statusAttr} == self::ST_NEVER) {
                // First sending and retryable
                $mail->{$this->retryAttr} = 0;
                $mail->{$this->statusAttr} = self::ST_RETRY;
            } elseif (
                ++$mail->{$this->retryAttr} < $this->retryTimes
                && $mail->{$this->statusAttr} == self::ST_RETRY
            ) {
                // Retry sending and still retryable for next time
            } else {
                // Final failed.
                $mail->{$this->statusAttr} = self::ST_FAILED;
            }
        }
    }

    /**
     * Apply anti-spam rules: when M mails are sent, pause for N seconds,
     * where M and corresponding N is set in attributes $spamRules.
     * The efficiency of this function depends on the minimal step count (M value) in the spam rules.
     * When the minimum step count is greater than 12, it's recommanded to use method "applySpamRules"
     * to save more time. To use that one, set attribute "useLiteAntiSpams" to false.
     *
     * @param $currentSent int Number of mails sent.
     * @param $isTest bool Set to false for running perfermance test, where will not perform actual system sleep.
     *
     * @return bool Whether system slept or not.
     */
    public function applySpamRulesLite($currentSent, $isTest = false)
    {
        if (empty($this->spamRules) || ! is_array($this->spamRules)) {
            return false; // return is spamRules is not set.
        } elseif (empty($this->_nextStop)) { //first time to apply spam rules
            $this->_nextStop = krsort($this->spamRules) || true;
        }
        foreach ($this->spamRules as $stopCount => $sec) {
            if ($currentSent % $stopCount == 0) {
                $isTest || $this->consoleLog(
                    "[lite] Apply spam rule: sleep {$sec} secs after {$stopCount} sent."
                );
                return $isTest || sleep($sec);
            }
        }
    }

    /**
     * Apply anti-spam rules: when M mails are sent, pause for N seconds,
     * where M and corresponding N is set in attributes $spamRules.
     * This method applys pausing by calculating next stop count.
     * The efficiency of this function depends on the minimal step count (M value) in the spam rules.
     * When the minimum step count is greater than 12, it's recommanded to use this one;
     * otherwise "applySpamRulesLite" (the lite version of this method) works more efficiently.
     * To use the lite version, set attribute "useLiteAntiSpams" to true.
     *
     * @param $currentSent int Number of mails sent.
     * @param $isTest bool Set to false for running perfermance test, where will not perform actual system sleep.
     *
     * @return bool Whether system slept or not.
     */
    public function applySpamRules($currentSent, $isTest = false)
    {
        if (empty($this->spamRules) || ! is_array($this->spamRules)) {
            return false; // return is spamRules is not set.
        } elseif (empty($this->_nextStop)) {
            // first time to apply spam rules, init the nextStop with biggest stop count
            unset($this->spamRules[0]); // 0 sent to pause makes no sense
            krsort($this->spamRules);
            $this->_nextStop[1] = end($this->spamRules);
            $this->_nextStop[0] = key($this->spamRules);
        }

        if ($currentSent == $this->_nextStop[0]) {
            $isTest || $this->consoleLog(
                "Apply spam rule: sleep {$this->_nextStop[1]} secs after {$this->_nextStop[0]} sent."
            );
            $isSlept = $isTest || sleep($this->_nextStop[1]);
            // start to calculate next stop sent count
            $this->_nextStop[1] = reset($this->spamRules);
            $this->_nextStop[0] += key($this->spamRules);
            // find out the least next stop count
            foreach ($this->spamRules as $stepCount => $sec) {
                $tryCount = $currentSent + $stepCount - ($currentSent % $stepCount);
                ($tryCount <= $this->_nextStop[0]) && ($this->_nextStop = [$tryCount, $sec]);
            }
            // find ou the correct stop second match the least next stop count
            foreach ($this->spamRules as $stepCount => $sec) {
                if ($this->_nextStop[0] % $stepCount == 0) {
                    $this->_nextStop[1] = $sec;
                    break;
                }
            }
            return $isSlept === 0;
        }
        return false;
    }

    /**
     * Signature getter.
     * Sleep 1 micro second when renew to ensure the sigature is always unique.
     * @param bool $renew
     * @return string
     */
    public function getSignature($renew = false)
    {
        if ($renew || is_null($this->_signature)) {
            usleep(1);
            $this->_signature = md5(microtime(true) . $this->serverID);
        }
        return $this->_signature;
    }

    /**
     * Mailer adaptor getter.
     * @param bool $renew Whether to return a new mailer adaptor.
     * @return \yii\mail\MailerInterface
     */
    public function getMailer($renew = false)
    {
        if (is_null($this->_mailer) || $renew) {
            if (is_string($this->mailer)) {
                try {
                    $this->_mailer = Yii::$app->get($this->mailer);
                } catch (Exception $e) {
                    $this->_mailer = Yii::createObject($this->mailer);
                }
            } elseif (is_array($this->mailer)) {
                $this->_mailer = Yii::createObject($this->mailer);
            } else {
                throw new Exception('Unknown mailer configuration.');
            }
        }
        return $this->_mailer;
    }

    /**
     *
     * @return \yii\db\ActiveRecord The mail AR retrieved from database and to be sent.
     */
    public function getFetchedMail()
    {
        return $this->_fetchedMail;
    }

    /**
     * Terminate application when user cancels operations or some error happens.
     */
    public function userCancel()
    {
        $this->stdout("User canceled.\n", Console::FG_YELLOW);
        Yii::$app->end();
    }

    /**
     * Log method.
     * @param string $msg
     * @param bool $enableTS
     * @param bool $returnLine
     * @param bool | int $padLength Dots will be appended till this length.
     */
    public function consoleLog(
        $msg,
        $enableTS = true,
        $fgColor = null,
        $returnLine = true,
        $padLength = false
    )
    {
        if ($enableTS) {
            $msg = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
        }
        if ($padLength) {
            $msg = str_pad($msg, $padLength, '.', STR_PAD_RIGHT);
        }
        if ($returnLine) {
            $msg .= PHP_EOL;
        }
        $this->stdout($msg, $fgColor);
    }


    /**
     * @overriding
     * @see \yii\base\Object::init()
     */
    public function init()
    {
        parent::init();
        try {
            //Initialize template model of the mail AR.
            $this->_templateModel = Yii::createObject($this->modelClass);
            $this->_db = $this->_templateModel->getDb();

            if ($this->sentByBehavior) {
                $this->sentByBehavior = Yii::createObject($this->sentByBehavior);
            }

        } catch (\ReflectionException $refE) {
            $route = explode('/', Yii::$app->getRequest()->resolve()[0]);
            if (isset(Yii::$app->coreCommands()[$route[0]]) || empty($route[0])) {
                return;
            }

            $action = isset($route[1]) ? $route[1] : '';
            if (!$this->installerMode) {
                $err = "Model class <{$this->modelClass}> not found." . PHP_EOL;
                $err .= "Please install the module first before using it.";
                $err .= "(Instruction in QuickStart section of README file.)" . PHP_EOL;
                $err .= "If the module is already installed, ";
                $err .= "please check if the attribute \"modelClass\" defines an available Model class." . PHP_EOL;
                $this->stderr($err, Console::FG_RED);
                Yii::$app->end(1);
            } elseif (!in_array($action, array_keys($this->installerActions))) {
                $err = 'Only actions: ' . implode(', ', array_keys($this->installerActions));
                $err .= ' are available in current installer mode.' . PHP_EOL;
                $err .= 'Please set option "installerMode" to false (default) to use other commands.' . PHP_EOL;
                $this->stderr($err, Console::FG_RED);
                Yii::$app->end(1);
            }
        } catch (Exception $e) {
            throw $e;
        }

    }

    /**
     * @overriding
     * @see \yii\console\Controller::options()
     */
    public function options($actionID)
    {
        empty($actionID) && ($actionID = $this->defaultAction);
        $actionOptions = [
            'send-queue' => [
                'testMode',
                'serverID',
                'maxSent',
                'signSize',
                'pageSize',
                'retryTimes',
                'signUnassigned',
                'renewSignature',
                'spamRules'
            ]
        ];

        if (isset($actionOptions[$actionID])) {
            return array_merge(parent::options($actionID), $actionOptions[$actionID]);
        } else {
            return parent::options($actionID);
        }

    }


}

?>
