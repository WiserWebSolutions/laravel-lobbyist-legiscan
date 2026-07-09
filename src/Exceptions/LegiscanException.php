<?php

namespace WiserWebSolutions\Lobbyist\Legiscan\Exceptions;

use WiserWebSolutions\Lobbyist\Exceptions\LobbyistException;

class LegiscanException extends LobbyistException
{
    public static function missingKey(): self
    {
        return new self('LegiScan API key is missing. Set LEGISCAN_API_KEY in .env');
    }

    public static function missingBaseUri(): self
    {
        return new self('LegiScan API base URI is missing. Set LEGISCAN_BASE_URI in .env');
    }

    public static function apiError(string $message): self
    {
        return new self("LegiScan API error: {$message}");
    }
}
