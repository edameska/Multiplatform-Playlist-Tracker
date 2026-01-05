<?php
namespace app\components\adapters;

use app\components\SpotifyService;
use Yii;

class SpotifyAdapter
{
    private SpotifyService $service;

    public function __construct(SpotifyService $service)
    {
        $this->service = $service;
    }

    public function setTokens(string $accessToken, ?string $refreshToken = null): void
    {
        $this->service->setAccessToken($accessToken, $refreshToken);
    }

    public function getUser(): array
    {
        $userJson = $this->service->apiGet('/me');
        return json_decode($userJson, true) ?? [];
    }

    public function getPlaylists(): array
    {
        $json = $this->service->getUserPlaylists();
        $data = json_decode($json, true) ?? [];

        return array_map(fn($p) => [
            'id' => $p['id'],
            'name' => $p['name'],
            'track_count' => $p['tracks']['total'],
        ], $data['items'] ?? []);
    }

    /**
     * Fetch all tracks for a playlist with pagination.
     */
    public function getPlaylistTracks(string $playlistId, int $limit = 100, int $offset = 0): array
{
    $allTracks = [];

    do {
        $json = $this->service->getPlaylistTracks($playlistId, $limit, $offset);
        $data = json_decode($json, true) ?? [];
        $items = $data['items'] ?? [];

        foreach ($items as $item) {
            if (!isset($item['track']) || ($item['track']['is_local'] ?? false)) continue;
            $track = $item['track'];
            $allTracks[] = [
                'id' => $track['id'] ?? null,
                'title' => $track['name'] ?? '',
                'artist' => implode(', ', array_column($track['artists'] ?? [], 'name')),
                'album' => $track['album']['name'] ?? '',
                'duration_ms' => $track['duration_ms'] ?? 0,
                'preview_url' => $track['preview_url'] ?? null,
                'raw' => json_encode($track, JSON_UNESCAPED_UNICODE),
            ];
        }

        $offset += $limit;
    } while (!empty($items));

    Yii::info("Fetched " . count($allTracks) . " tracks from playlist $playlistId", __METHOD__);

    return $allTracks;
}

}
