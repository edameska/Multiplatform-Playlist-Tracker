<?php
namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * PlaylistTrack model
 *
 * @property int $playlist_id
 * @property int $track_id
 * @property int|null $position
 * @property bool $added_by_api
 * @property string $detected_at
 *
 * @property Playlist $playlist
 * @property Track $track
 */
class PlaylistTrack extends ActiveRecord
{
    public static function tableName()
    {
        return 'playlist_track';
    }

    public function rules()
    {
        return [
            [['playlist_id', 'track_id'], 'required'],
            [['playlist_id', 'track_id', 'position'], 'integer'],
            [['added_by_api'], 'boolean'],
            [['detected_at'], 'safe'],
        ];
    }

    public function getPlaylist()
    {
        return $this->hasOne(Playlist::class, ['id' => 'playlist_id']);
    }

    public function getTrack()
    {
        return $this->hasOne(Track::class, ['id' => 'track_id']);
    }
}
