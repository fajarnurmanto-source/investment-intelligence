<?php

namespace App\Services;

class RuntimeCaBundleService
{
    /** @return array{ready:bool,path:string,sha256:?string,certificate_count:int,reason:string} */
    public static function inspect(?string $runtimeRoot = null): array
    {
        $runtimeRoot ??= (string) (getenv('II_RUNTIME_ROOT') ?: '');
        $path = rtrim($runtimeRoot, '\\/ ').DIRECTORY_SEPARATOR.'certs'.DIRECTORY_SEPARATOR.'cacert.pem';
        // Windows ACLs can make PHP's is_readable() report false even when the
        // current process can open the file. The actual read below is the
        // authoritative readiness check.
        if ($runtimeRoot === '' || ! is_file($path)) {
            return ['ready' => false, 'path' => $path, 'sha256' => null, 'certificate_count' => 0, 'reason' => 'MISSING'];
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return ['ready' => false, 'path' => $path, 'sha256' => null, 'certificate_count' => 0, 'reason' => 'UNREADABLE'];
        }
        $sha256 = hash('sha256', $contents);
        $count = substr_count($contents, '-----BEGIN CERTIFICATE-----');
        $metadataPath = dirname($path).DIRECTORY_SEPARATOR.'cacert.metadata.json';
        $metadata = is_file($metadataPath) ? json_decode((string) file_get_contents($metadataPath), true) : null;
        $metadataHash = is_array($metadata) ? strtolower((string) ($metadata['sha256'] ?? '')) : '';
        $ready = strlen($contents) > 100000 && $count >= 70 && hash_equals($sha256, $metadataHash);

        return [
            'ready' => $ready,
            'path' => realpath($path) ?: $path,
            'sha256' => $sha256,
            'certificate_count' => $count,
            'reason' => $ready ? 'READY' : ($metadataHash === '' ? 'METADATA_MISSING' : 'INTEGRITY_INVALID'),
        ];
    }
}
