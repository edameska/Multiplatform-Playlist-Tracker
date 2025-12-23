<?php
namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * @property int         $id
 * @property int|null    $user_id
 * @property string      $platform
 * @property string|null $platform_user_id
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property string|null $scope
 * @property string|null $token_type
 * @property string|null $expires_at
 * @property array|null  $raw
 * @property string      $created_at
 * @property string      $updated_at
 */
class ApiAccount extends ActiveRecord
{
    public static function tableName()
    {
        return 'api_account';
    }

    public function rules()
    {
        return [
            [['user_id'], 'integer'],
            [['platform'], 'required'],
            [['access_token', 'refresh_token', 'scope'], 'string'],
            [['raw'], 'safe'],
            [['platform', 'token_type'], 'string', 'max' => 50],
            [['platform_user_id'], 'string', 'max' => 255],
            [['expires_at'], 'safe'],
            [['platform', 'user_id', 'platform_user_id'], 'unique', 'targetAttribute' => ['user_id', 'platform', 'platform_user_id']],
        ];
    }

    public function beforeSave($insert)
    {
        $now = date('Y-m-d H:i:s');
        $this->updated_at = $now;
        if ($insert && !$this->created_at) {
            $this->created_at = $now;
        }

        // Ensure expires_at is in correct format if it’s a timestamp
        if (is_int($this->expires_at)) {
            $this->expires_at = date('Y-m-d H:i:s', $this->expires_at);
        }

        return parent::beforeSave($insert);
    }

    /**
     * Get a single account for the user & platform.
     */
    public static function getAccount($userId, $platform)
    {
        $account = ApiAccount::findOne([
            'user_id'  => Yii::$app->user->id,
            'platform' => 'spotify'
        ]);

        $this->service->setAccessToken($account->access_token);
        $this->service->setRefreshToken($account->refresh_token);

    }

    /**
     * Create or update an ApiAccount record.
     */
    public static function createOrUpdate($userId, $platform, $data)
    {
        $model = static::getAccount($userId, $platform);
        
        if (!$model) {
            $model = new static();
            $model->user_id = $userId;
            $model->platform = $platform;
        }

        foreach ($data as $key => $value) {
            if ($model->hasAttribute($key)) {
                $model->$key = $value;
            }
        }

        if (!$model->save()) {
            throw new \Exception('Could not save ApiAccount: ' . json_encode($model->errors));
        }

        return $model;
    }

    /**
     * Check if the token is expired.
     */
    public function isExpired(): bool
    {
        if (!$this->expires_at) {
            return false;
        }
        return strtotime($this->expires_at) <= time();
    }

    /**
     * Decode raw JSON automatically.
     */
    public function getRawDecoded()
    {
        return is_string($this->raw) ? json_decode($this->raw, true) : $this->raw;
    }
}
