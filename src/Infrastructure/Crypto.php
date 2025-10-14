<?php
declare(strict_types=1);

namespace App\Infrastructure;

final class Crypto
{
    private string $key;

    public function __construct(?string $appKey, string $baseDir)
    {
        $key = $appKey ?: '';
        if ($key === '') {
            $keyFile = rtrim($baseDir, '/').'/var/app.key';
            if (is_readable($keyFile)) {
                $key = trim((string)file_get_contents($keyFile));
            } else {
                // generate and persist
                $key = base64_encode(random_bytes(32));
                @file_put_contents($keyFile, $key);
                @chmod($keyFile, 0600);
            }
        }
        // normalize base64 key to 32 bytes
        $raw = base64_decode($key, true);
        if ($raw === false || strlen($raw) < 32) {
            // fallback derive from given string
            $raw = hash('sha256', $key, true);
        }
        $this->key = substr($raw, 0, 32);
    }

    public function encrypt(?string $plaintext): ?string
    {
        if ($plaintext === null || $plaintext === '') return null;
        // Prefer sodium if available
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ct = sodium_crypto_secretbox($plaintext, $nonce, $this->key);
            return 's:'.base64_encode($nonce.$ct);
        }
        // Fallback to openssl AES-256-GCM
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) return null;
        return 'o:'.base64_encode($iv.$tag.$ct);
    }

    public function decrypt(?string $ciphertext): ?string
    {
        if ($ciphertext === null || $ciphertext === '') return null;
        if (str_starts_with($ciphertext, 's:')) {
            $bin = base64_decode(substr($ciphertext, 2), true);
            if ($bin === false) return null;
            $nonce = substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ct = substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $pt = sodium_crypto_secretbox_open($ct, $nonce, $this->key);
            return $pt === false ? null : $pt;
        }
        if (str_starts_with($ciphertext, 'o:')) {
            $bin = base64_decode(substr($ciphertext, 2), true);
            if ($bin === false) return null;
            $iv = substr($bin, 0, 12);
            $tag = substr($bin, 12, 16);
            $ct = substr($bin, 28);
            $pt = openssl_decrypt($ct, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
            return $pt === false ? null : $pt;
        }
        return null;
    }
}
