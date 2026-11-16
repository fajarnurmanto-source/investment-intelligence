<?php

namespace App\Contracts;

interface OfficialDataConnector
{
    public function sourceKey(): string;

    /** @return array<string, mixed> */
    public function downloadAndNormalize(string $identifier, string $contact): array;
}
