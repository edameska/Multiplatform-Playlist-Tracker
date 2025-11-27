<?php
namespace app\models;

use yii\db\ActiveRecord;
use yii\web\IdentityInterface;
use Yii;

class User extends ActiveRecord implements IdentityInterface
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'users';
    }

    /**
     * {@inheritdoc}
     */
    public static function findIdentity($id)
    {
        return static::findOne($id);
    }

    /**
     * {@inheritdoc}
     * Not using access tokens for now
     */
    public static function findIdentityByAccessToken($token, $type = null)
    {
        return null; // disable for now
    }

    /**
     * Finds user by username
     */
    public static function findByUsername($username)
    {
        return static::findOne(['username' => $username]);
    }

    /**
     * {@inheritdoc}
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * {@inheritdoc}
     * Generate a dynamic authKey 
     */
   public function getAuthKey()
    {
        return hash('sha256', $this->id . Yii::$app->request->cookieValidationKey);
    }

    /**
     * {@inheritdoc}
     */
    public function validateAuthKey($authKey)
    {
        return $this->getAuthKey() === $authKey;
    }

    /**
     * Validates password
     */
    public function validatePassword($password)
    {
        return hash('sha256', $password . $this->salt) === $this->password_hash;
    }
}
