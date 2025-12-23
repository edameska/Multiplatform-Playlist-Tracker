<?php
namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * Track model
 *
 * @property int $id
 * @property string $platform
 * @property string $platform_id
 * @property string $title
 * @property string|null $artist
 * @property string|null $album
 * @property int|null $duration_ms
 * @property string $created_at
 * @property string $updated_at
 */
class Track extends ActiveRecord
{
    public static function tableName()
    {
        return 'track';
    }

    public function rules()
    {
        return [
            [['platform', 'platform_id', 'title'], 'required'],
            [['duration_ms'], 'integer'],
            [['platform', 'platform_id', 'title', 'artist', 'album'], 'string', 'max' => 255],
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
}
