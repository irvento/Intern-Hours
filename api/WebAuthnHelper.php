<?php
/**
 * WebAuthn Lightweight Cryptographic Helper Class
 * Fully self-contained, zero-dependency, pure PHP X.509 EC key compiler and signature verifier.
 */

class CBORDecoder {
    private $data;
    private $offset = 0;

    public function __construct($binary) {
        $this->data = $binary;
    }

    public function decode() {
        if ($this->offset >= strlen($this->data)) return null;
        $byte = ord($this->data[$this->offset++]);
        $type = $byte >> 5;
        $val = $byte & 0x1f;

        if ($val === 24) {
            $val = ord($this->data[$this->offset++]);
        } elseif ($val === 25) {
            $val = (ord($this->data[$this->offset++]) << 8) | ord($this->data[$this->offset++]);
        } elseif ($val === 26) {
            $val = (ord($this->data[$this->offset++]) << 24) | 
                   (ord($this->data[$this->offset++]) << 16) | 
                   (ord($this->data[$this->offset++]) << 8) | 
                   ord($this->data[$this->offset++]);
        }

        switch ($type) {
            case 0: // Unsigned Int
                return $val;
            case 1: // Negative Int
                return -1 - $val;
            case 2: // Byte String
                $str = substr($this->data, $this->offset, $val);
                $this->offset += $val;
                return $str;
            case 3: // Text String
                $str = substr($this->data, $this->offset, $val);
                $this->offset += $val;
                return $str;
            case 4: // Array
                $arr = [];
                for ($i = 0; $i < $val; $i++) {
                    $arr[] = $this->decode();
                }
                return $arr;
            case 5: // Map
                $map = [];
                for ($i = 0; $i < $val; $i++) {
                    $k = $this->decode();
                    $v = $this->decode();
                    $map[$k] = $v;
                }
                return $map;
            default:
                return null;
        }
    }
}

class WebAuthnHelper {
    public static function createChallenge() {
        return bin2hex(random_bytes(32));
    }

    public static function base64url_decode($data) {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    public static function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Extracts Credential ID and Public Key (PEM) from WebAuthn Registration response
     */
    public static function verifyRegistration($clientDataJSON_b64, $attestationObject_b64, $expectedChallenge) {
        $clientDataJSON = self::base64url_decode($clientDataJSON_b64);
        $attestationObject = self::base64url_decode($attestationObject_b64);

        // 1. Verify clientDataJSON parameters
        $clientData = json_decode($clientDataJSON, true);
        if (!$clientData || $clientData['type'] !== 'webauthn.create') {
            throw new Exception("Invalid registration event type.");
        }

        // Verify challenge
        $decodedChallenge = self::base64url_decode($clientData['challenge']);
        if (bin2hex($decodedChallenge) !== $expectedChallenge && $clientData['challenge'] !== $expectedChallenge) {
            throw new Exception("Challenge mismatch.");
        }

        // 2. Decode Attestation CBOR
        $cbor = new CBORDecoder($attestationObject);
        $attestationMap = $cbor->decode();
        if (!isset($attestationMap['authData'])) {
            throw new Exception("Invalid attestation structure: missing authData.");
        }

        $authData = $attestationMap['authData'];
        $authDataLen = strlen($authData);
        if ($authDataLen < 37) {
            throw new Exception("authData too short.");
        }

        // rpIdHash: 32 bytes (0..32)
        // flags: 1 byte (32)
        // signCount: 4 bytes (33..37)
        $flags = ord($authData[32]);
        $hasAttestedCredentialData = ($flags & 0x40) !== 0;

        if (!$hasAttestedCredentialData) {
            throw new Exception("Biometric platform authenticator failed to provide credential details.");
        }

        // Attested Credential Data structure:
        // aaguid: 16 bytes (37..53)
        // credentialIdLength: 2 bytes (53..55)
        $credIdLen = (ord($authData[53]) << 8) | ord($authData[54]);
        
        // credentialId: $credIdLen bytes (55 .. 55+$credIdLen)
        $credentialId = substr($authData, 55, $credIdLen);

        // credentialPublicKey: variable length CBOR starting at offset 55 + $credIdLen
        $pubKeyCborBinary = substr($authData, 55 + $credIdLen);
        $pubKeyCbor = new CBORDecoder($pubKeyCborBinary);
        $pubKeyMap = $pubKeyCbor->decode();

        if (!$pubKeyMap) {
            throw new Exception("Could not decode public key map.");
        }

        // ES256 Public Key CBOR Map constants:
        // 1 = key type (2 = EC2)
        // 3 = algorithm (-7 = ES256)
        // -1 = curve (1 = secp256r1)
        // -2 = X coordinate byte string (32 bytes)
        // -3 = Y coordinate byte string (32 bytes)
        if (!isset($pubKeyMap[-2]) || !isset($pubKeyMap[-3])) {
            throw new Exception("Only ES256 Elliptic Curve biometrics are supported.");
        }

        $x = $pubKeyMap[-2];
        $y = $pubKeyMap[-3];

        // Construct ASN.1 DER SubjectPublicKeyInfo for secp256r1
        $derPublicKey = pack('H*', '3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
        
        $pem = "-----BEGIN PUBLIC KEY-----\n" . 
               chunk_split(base64_encode($derPublicKey), 64, "\n") . 
               "-----END PUBLIC KEY-----";

        return [
            'credentialId' => self::base64url_encode($credentialId),
            'publicKey' => $pem
        ];
    }

    /**
     * Verifies WebAuthn Authentication (Signature check) for Clock In/Out
     */
    public static function verifyAuthentication($clientDataJSON_b64, $authenticatorData_b64, $signature_b64, $publicKeyPEM, $expectedChallenge) {
        $clientDataJSON = self::base64url_decode($clientDataJSON_b64);
        $authenticatorData = self::base64url_decode($authenticatorData_b64);
        $signature = self::base64url_decode($signature_b64);

        // 1. Verify challenge
        $clientData = json_decode($clientDataJSON, true);
        if (!$clientData || $clientData['type'] !== 'webauthn.get') {
            throw new Exception("Invalid auth event type.");
        }

        $decodedChallenge = self::base64url_decode($clientData['challenge']);
        if (bin2hex($decodedChallenge) !== $expectedChallenge && $clientData['challenge'] !== $expectedChallenge) {
            throw new Exception("Challenge mismatch.");
        }

        // 2. Validate Signature
        // The signature is signed over: authenticatorData + SHA-256(clientDataJSON)
        $clientDataHash = hash('sha256', $clientDataJSON, true);
        $signedData = $authenticatorData . $clientDataHash;

        // OpenSSL ECDSA signature verification
        $ok = openssl_verify($signedData, $signature, $publicKeyPEM, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new Exception("Biometric signature verification failed.");
        }

        return true;
    }
}
