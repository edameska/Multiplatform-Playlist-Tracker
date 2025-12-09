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
            [['platform'], 'required'],
            [['user_id'], 'integer'],
            [['access_token', 'refresh_token', 'scope', 'raw'], 'string'],
            [['expires_at', 'created_at', 'updated_at'], 'safe'],
            [['platform', 'token_type', 'platform_user_id'], 'string', 'max' => 255],
        ];
    }

    public function beforeSave($insert)
    {
        // If you want local timestamps (optional; you already have a DB trigger)
        // $this->updated_at = date('c');

        return parent::beforeSave($insert);
    }

    /**
     * Get a single account for the user & platform.
     */
    public static function getAccount($userId, $platform)
    {
        return static::findOne([
            'user_id' => $userId,
            'platform' => $platform
        ]);
    }

    /**
     * Create or update an API account entry for the user.
     *
     * $data example:
     * [
     *   'access_token' => '...',
     *   'refresh_token' => '...',
     *   'expires_at' => '2025-01-01 12:30:00',
     *   'platform_user_id' => 'spotify-uid',
     *   'raw' => json_encode($tokens)
     * ]
     */
    public static function createOrUpdate($userId, $platform, $data)
    {
        $model = static::getAccount($userId, $platform);
        
        if (!$model) {
            $model = new static();
            $model->user_id = $userId;
            $model->platform = $platform;
        }

        // Mass-assign fields
        foreach ($data as $key => $value) {
            if ($model->hasAttribute($key)) {
                $model->$key = $value;
            }
        }

        $model->save();
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
