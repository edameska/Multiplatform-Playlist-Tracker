<?php

namespace app\models;

use yii\base\Model;
use app\models\User;

class SignupForm extends Model
{
    public $username;
    public $password;
    public $password_repeat;

    public function rules()
    {
        return [
            [['username', 'password', 'password_repeat'], 'required'],
            ['username', 'string', 'min' => 3, 'max' => 50],
            ['username', 'unique', 'targetClass' => User::class, 'message' => 'Username already taken.'],
            ['password', 'string', 'min' => 6],
            ['password_repeat', 'compare', 'compareAttribute' => 'password', 'message' => "Passwords don't match."],
        ];
    }

    public function signup()
    {
        if (!$this->validate()) {
            return null;
        }

        $user = new User();
        $user->username = $this->username;

        // Generate a random salt
        $salt = bin2hex(random_bytes(16)); // 32-character hex salt
        $user->salt = $salt;

        // Hash password with salt
        $user->password_hash = hash('sha256', $this->password . $salt);

        return $user->save() ? $user : null;
    }
}
