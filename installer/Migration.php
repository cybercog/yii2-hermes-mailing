<?php
/**
 * @link https://github.com/thinker-g/yii2-hermes-mailing
 * @copyright Copyright (c) Thinker_g (Jiyan.guo@gmail.com)
 * @license MIT
 * @version v1.0.0
 * @author Thinker_g
 */
namespace thinkerg\HermesMailing\installer;

use yii\db\Migration as YiiMigration;
use Yii;

/**
 *
 * @author tlsadmin
 * @property string $tableName
 */
class Migration extends YiiMigration
{

    public $table = '{{%hermes_mail}}';

    public $columns = [
        'id' => 'int primary key auto_increment',
        'to' => 'varchar(50)',
        'from' => 'varchar(50)',
        'from_name' => 'varchar(50)',
        'reply_to' => 'varchar(50)',
        'is_html' => 'bool default true',
        'subject' => 'varchar(100)',
        'body' => 'text',
        'created' => 'timestamp default current_timestamp',
        'last_sent' => 'timestamp',
        'retry_times' => 'int',
        'status' => 'varchar(10)',
        'assigned_to_svr' => 'int',
        'sent_by' => 'int',
        'signature' => 'varchar(32)',
    ];


    public function up()
    {
        $this->createTable($this->tableName, $this->columns);
        return true;
    }

    public function down()
    {
        $this->dropTable($this->tableName);
        return true;
    }

    public function getTableName()
    {
        return $this->db->getSchema()
            ->getRawTableName($this->table);
    }

    public function isTableExists()
    {
        return in_array($this->getTableName(), $this->db->getSchema()->tableNames);
    }

}

?>