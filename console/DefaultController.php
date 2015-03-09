<?php
namespace thinkerg\HermesMailing\console;

use Yii;
use yii\console\Controller;
use yii\helpers\Console;
use yii\base\Exception;
use yii\base\Object;

/**
 *
 * @author tlsadmin
 *
 * @property $module \thinkerg\HermesMailing\Module
 */
class DefaultController extends Controller
{

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
     * Whether to enable test mode. If set to ture, command will call testSend() method of the mailer adaptor.
     * Default to false.
     * @var bool
     */
    public $testMode = false;

    /**
     * Email AR model name.
     * Make sure the model class is imported after installation.
     * LEAVE THIS NULL, unless the model name is not generated by the table name.
     * If this is set, table name will be retrieved by "tableName()" of its instance.
     * This is for using some already existed AR. Won't be needed for default installation.
     * @var string
     */
    public $modelClass = 'app\models\EmailQueue';

    /**
     * Column (attribute name) of email table to store process signature.
     * This is mandatory attribute to the AR model. There must be a column to play this role.
     * Default to "signature".
     * @var string
     */
    public $signatureCol = 'signature'; // mandatory  column

    /**
     * Column (attribute name) of email table to store sending status.
     * This is mandatory attribute to the AR model. There must be a column to play this role.
     * Default to "status".
     * @var string
     */
    public $statusCol = 'status'; // mandatory  column

    /**
     * Column (attribute name) of email table to store retry times.
     * Optional attribute, will only take effects when it exists in the model.
     * Default to "retry_times".
     * @var string
     */
    public $retryCol = 'retry_times';

    /**
     * Column (attribute name) of email table to assign emails to be send by the processes from specific server.
     * Optional attribute, will only take effects when it exists in the model.
     * Default to "send_by".
     * @var string
     */
    public $sendByCol = 'send_by';

    /**
     * Column (attribute name) of email table to store the server id that actually sent this email out.
     * Optional attribute, will only take effects when it exists in the model.
     * Default to "sent_by".
     * @var string
     */
    public $sentByCol = 'sent_by';

    /**
     * Server id of processes start on CURRENT SERVER. The process will retrieve emails assigned to THIS server id in central database.
     * When this is distributed on different servers, make sure this is unique among all servers which is connecting to the same central db.
     * Default to 0.
     * @var int
     */
    public $serverId = 0;

    /**
     * When this number of mails are sent, the program will shutdown after current signed mails are all processed.
     * @var int
     */
    public $maxSent = null;

    /**
     * How many emails to sign each time.
     * Default to 10.
     * @var int
     */
    public $signSize = 50;

    /**
     * How many signed emails are loaded to memory each time.
     * Default to 10.
     * @var int
     */
    public $pageSize = 10;

    /**
     * How many times to resend after first send is failed.
     * Only take effects when column specified by EmailQueueCommand::$retryCol exists.
     * Default to 0.
     * @var int
     */
    public $retryTimes = 0;

    /**
     * Spam rules for current PROCESS, each element in format m=>n, where m is the SENT_COUNT n is the SECOND.
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
     * Mailer adaptor definition that used to really send email.
     * 'class' element is the alias of adaptor class, all rests are attributes of the adaptor.
     * The specified class must implement interface IMailerAdaptor and the send/testSend methods must return boolean.
     * Default to array('class' => 'EmailQueue.adaptors.ExampleMailerAdaptor').
     * @var array
     */
    public $mailerAdaptor = 'EmailQueue.adaptors.ExampleMailerAdaptor';

    /**
     * Instance of CDbConnection used.
     * Left it to null to use the db connection of main console application.
     * Use array to initialize a new CDbConnection object.
     * Or use a exsiting CDbConnection instance directly.
     * @var null|array|\yii\db\Connection
     */
    public $_db;

    /**
     * Instance of mailer adaptor specified by EmailQueue::$mailerAdaptor.
     * @var IMailerAdaptor
     */
    private $_mailerAdaptor;

    /**
     * Emailing counter of current process.
     * @var int
     */
    private $_sendingCount = 0;

    /**
     * Used internally while installing.
     * @var bool
     */
    private $_readyToInstall;

    /**
     * Signature of current process.
     * @var string
     */
    private $_signature;

    /**
     * Static model of mail AR.
     * Returned by invoking MODEL_NAME::model();
     * Default to object of QueuedEmail.
     * @var \yii\db\ActiveRecord
     */
    private $_templateModel;

    /**
     * Used by method DefaultController::applySpamRules();
     * DON'T modify it.
     * @var array
     */
    private $_nextStop;

    /* =============== Command options ============================ */
    public $signUnassigned = true;

    public $renewSignature = false;

    /* =============== End of command options ===================== */

    /*
     * ============================================================
     *
     * Attributes below are for installion, and might be used at most for once.
     * If you are using your own Email AR Model, please do not touch this part.
     *
     * ============================================================
     */
    public $installerMode = false;

    public $installerActions = [
        'install' => 'thinkerg\HermesMailing\installer\actions\InstallAction',
        'uninstall' => 'thinkerg\HermesMailing\installer\actions\UninstallAction',
        'fill4test' => 'thinkerg\HermesMailing\installer\actions\Fill4TestAction'
    ];

    /* (non-PHPdoc)
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
        while ($signedNum = $this->signEmails($this->signUnassigned, $this->renewSignature)) {
            $this->consoleLog("Signed $signedNum entries with signature $this->_signature.");
            $this->sendSigned();
            $this->consoleLog("{$signedNum} emails processed by signature: {$this->signature}.");
            if (!empty($this->maxSent) && ($this->_sendingCount >= $this->maxSent)) {
                $this->consoleLog("Max sent limit ({$this->maxSent}) reached, shutting down.");
                break;
            }
        }
        $this->consoleLog("Emailing process (server id: {$this->serverId}) stopped.", true, console::FG_GREEN);
        return 0;
    }

    /**
     * Sign emails to claim emails send by CURRENT PROCESS, then signed emails will not be retrieved by other processes.
     *
     * @param bool $signUnassigned Whether to sign email those are not assigned to any server_id (IS NULL).
     * Take effects only when column specified by EmailQueueCommand::$sendByCol exists.
     * @param bool $renewSignature Whether to renew the signature when sign emails every time.
     *
     */
    protected function signEmails($signUnassigned = true, $renewSignature = false)
    {
        $modelClass = $this->_templateModel;
        $where = [$this->signatureCol => null];
        if ($modelClass->hasAttribute($this->sendByCol)) {
            $where = ['and', $where];
            if ($signUnassigned) {
                $where[] = ['or', [$this->sendByCol => $this->serverId], [$this->sendByCol => null]];
            } else {
                $where[] = [$this->sendByCol => $this->serverId];
            }
        }
        $cols = [$this->signatureCol => $this->getSignature($renewSignature)];
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
        $tplModel = $this->_templateModel;
        $where = ['and',
            [$this->signatureCol => $this->_signature],
            ['or',
                [$this->statusCol => self::ST_NEVER],
                [$this->statusCol => self::ST_RETRY]
            ]
        ];

        while ($fetchedMails = $tplModel::find()->where($where)->limit($this->pageSize)->all()) {
            foreach($fetchedMails as $mail) {
                // $isSent = $this->getMailerAdaptor()->{$this->testMode ? 'testSend' : 'send'}($mail, $this);
                $isSent = rand(0, 1);
                $this->_sendingCount++;
                $this->processEmailStatus($mail, $isSent);
                $mail->save(false);
                $this->applySpamRules();
            }
        }
    }

    /**
     * Determine status of one email AR. Be called after every email sent.
     * Result statuc can be "succeed", "failed" or "retry".
     * @param CActiveRecord $ar
     * @param bool $isSent
     */
    protected function processEmailStatus(\yii\db\ActiveRecord &$mail, $isSent)
    {
        if (! ($mail->hasAttribute($this->retryCol) && $this->retryTimes > 0)) {
            $mail->{$this->statusCol} = $isSent ? self::ST_SUCCEED : self::ST_FAILED;
            return;
        }

        if ($isSent) {
            if ($mail->{$this->statusCol} == self::ST_RETRY) {
                // Current is a retry sending.
                $mail->{$this->retryCol} ++;
            }
            $mail->{$this->statusCol} = self::ST_SUCCEED;
        } else {
            if ($mail->{$this->statusCol} == self::ST_NEVER) {
                // First sending and retryable
                $mail->{$this->retryCol} = 0;
                $mail->{$this->statusCol} = self::ST_RETRY;
            } elseif (++$mail->{$this->retryCol} < $this->retryTimes && $mail->{$this->statusCol} == self::ST_RETRY) {
                // Retry sending and still retryable for next time
            } else {
                // Final failed.
                $mail->{$this->statusCol} = self::ST_FAILED;
            }
        }
    }

    /**
     * Pause system by calculating sent count of NEXT STOP.
     * Only do one loop on spamRules array to calculate next stop, while sendingCount reaches to "NEXT STOP".
     * @return bool Whether system slept or not.
     */
    protected function applySpamRules()
    {
        if (empty ($this->spamRules) || ! is_array($this->spamRules)) {
            return false;
        } elseif (empty ($this->_nextStop)) {
            krsort($this->spamRules);
            unset($this->spamRules[0]);
            end($this->spamRules);
            $this->_nextStop = array(key($this->spamRules), current($this->spamRules));
            reset($this->spamRules);
        }

        if ($this->_sendingCount >= $this->_nextStop[0]) {
            $this->consoleLog("Apply spam rule: sleep {$this->_nextStop[1]} secs when {$this->_nextStop[0]} emails sent.");
            $isSlept = sleep($this->_nextStop[1]);
            foreach ($this->spamRules as $stepCount => $sec) {
                $tryCount = ((int)$this->_sendingCount / $stepCount) + $stepCount;
                if (empty($this->_nextStop)) {
                    $this->_nextStop = array($tryCount, $sec);
                } else if ($tryCount < $this->_nextStop[0]) {
                    $this->_nextStop = array($tryCount, $sec);
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
            $this->_signature = md5(microtime(true) . $this->serverId);
        }
        return $this->_signature;
    }

    /**
     * Mailer adaptor getter.
     * @param bool $renew Whether to return a new mailer adaptor.
     * @return IMailerAdaptor
     */
    public function getMailerAdaptor($renew = false)
    {
        return new Object();
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
    public function consoleLog($msg, $enableTS = true, $fgColor = Console::FG_BLUE, $returnLine = true, $padLength = false)
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


    /* (non-PHPdoc)
     * @see \yii\base\Object::init()
     */
    public function init()
    {
        parent::init();
        try {
            //Initialize template model of the mail AR.
            $this->_templateModel = Yii::createObject($this->modelClass);
            $this->_db = $this->_templateModel->getDb();

        } catch (\ReflectionException $refE) {
            $route = explode('/', Yii::$app->getRequest()->resolve()[0]);
            if ($route[0] == "help") {
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
            } elseif (!in_array($action, ['install', 'uninstall', 'fill-test-data'])) {
                $err = 'Only actions "install", "uninstall", "fill-test-data"';
                $err .= ' are available in installer mode.' . PHP_EOL;
                $err .= 'Please set option "installerMode" to false (default) to use other commands.' . PHP_EOL;
                $this->stderr($err, Console::FG_RED);
                Yii::$app->end(1);
            }
        } catch (Exception $e) {
            throw $e;
        }

    }

    /* (non-PHPdoc)
     * @see \yii\console\Controller::options()
     */
    public function options($actionID)
    {
        empty($actionID) && ($actionID = $this->defaultAction);
        $actionOptions = [
            'send-queue' => ['signUnassigned', 'renewSignature']
        ];

        if (isset($actionOptions[$actionID])) {
            return array_merge(parent::options($actionID), $actionOptions[$actionID]);
        } else {
            return parent::options($actionID);
        }

    }


}

?>
