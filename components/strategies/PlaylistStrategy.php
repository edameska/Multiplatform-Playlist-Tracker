<?php

interface PlaylistStrategy
{
    /*Returns the URL for OAuth*/
    public function getAuthUrl(): string;

    /*Exchanges the authorization code for an access token*/
    public function exchangeCodeForToken(string $code): array;

    /*Fetch playlists from platform as an array*/
    public function fetchPlaylists(string $accessToken): array;
}
