<?php
namespace app\components\adapters;

use Yii;
use app\components\YoutubeService;

class YoutubeAdapter
{
    private YoutubeService $service;

    public function __construct(YoutubeService $service)
    {
        $this->service = $service;
    }

    // Set access and refresh tokens from DB or controller
    public function setTokens(string $accessToken, ?string $refreshToken = null): void
    {
        $this->service->setAccessToken($accessToken, $refreshToken);
    }

    // Get all playlists for a given channel
    public function getPlaylists(): array
    {
        $items = $this->service->getPlaylists(50);

        return array_map(fn($p) => [
            'id' => $p['id'] ?? null,
            'title' => $p['title'] ?? 'Untitled',
            'itemCount' => $p['itemCount'] ?? 0,
        ], $items);
    }



    // Get all tracks for a playlist, handling pagination
    public function getPlaylistTracks(string $playlistId): array
{
    $allTracks = [];
    $pageToken = null;

    do {
        $result = $this->service->getPlaylistItems($playlistId, 50, $pageToken ?? '');
        $items = $result['items'] ?? [];
        $pageToken = $result['nextPageToken'] ?? null;

        Yii::info("Fetched " . count($items) . " tracks from playlist $playlistId, pageToken: " . ($pageToken ?? 'none'), __METHOD__);

        foreach ($items as $item) {
            $allTracks[] = [
                'id' => $item['id'] ?? null,
                'title' => $item['title'] ?? 'Untitled',
                'artist' => $item['channel'] ?? 'Unknown',
                'album' => '',
                'duration_ms' => 0,
                'preview_url' => $item['url'] ?? null,
            ];
        }
    } while ($pageToken);

    Yii::info("Total tracks fetched for playlist $playlistId: " . count($allTracks), __METHOD__);
    return $allTracks;
}



    // Helper to get channel ID for the authenticated user
    public function getMyChannelId(): ?string
    {
        $data = $this->service->apiGet('/channels', ['part' => 'id', 'mine' => 'true']);
        return $data['items'][0]['id'] ?? null;
    }
}
