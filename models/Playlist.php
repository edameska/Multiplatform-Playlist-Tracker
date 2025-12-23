<?php
namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * Playlist model
 *
 * @property int $id
 * @property int $api_account_id
 * @property string $platform
 * @property string $name
 * @property int|null $track_count
 * @property string|null $last_synced_at
 * @property string $created_at
 * @property string $updated_at
 *
 * @property ApiAccount $apiAccount
 */
class Playlist extends ActiveRecord
{
    public static function tableName()
    {
        return 'playlist';
    }

    public function rules()
    {
        return [
            [['api_account_id', 'platform', 'name'], 'required'],
            [['api_account_id', 'track_count'], 'integer'],
            [['last_synced_at'], 'safe'],
            [['platform', 'name'], 'string', 'max' => 255],
        ];
    }

    public function beforeSave($insert)
    {
        $now = date('Y-m-d H:i:s');
        $this->updated_at = $now;
        if ($insert && !$this->created_at) {
            $this->created_at = $now;
        }
        return parent::beforeSave($insert);
    }

    public function getApiAccount()
    {
        return $this->hasOne(ApiAccount::class, ['id' => 'api_account_id']);
    }
}
