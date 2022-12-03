<?php

namespace pdo_bolt\drivers\bolt;

use Bolt\protocol\Response;
use PDO;
use PDOException;

/**
 * @author Michal Stefanak
 * @link https://github.com/stefanak-michal/pdo-bolt
 */
trait ErrorTrait
{
    private string $errorCode = '00000';
    private array $failureContent = [];

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function errorInfo(): array
    {
        return [
            $this->errorCode,
            $this->failureContent['code'] ?? '',
            $this->failureContent['message'] ?? ''
        ];
    }

    private function checkResponse(Response $response): bool
    {
        switch ($response->getSignature()) {
            case $response::SIGNATURE_SUCCESS:
                return true;
            case $response::SIGNATURE_FAILURE:
                $this->handleError(BoltDriver::ERR_MESSAGE_FAILURE, $response->getContent());
                return false;
            case $response::SIGNATURE_IGNORED:
                $this->handleError(BoltDriver::ERR_MESSAGE_IGNORED, ['code' => 'IGNORED', 'message' => 'Request has not been carried out.']);
                return false;
        }
        return false;
    }

    private function handleError(string $errorCode = PDO::ERR_NONE, array $failureContent = [], ?int $errorMode = null): void
    {
        $this->errorCode = $errorCode;
        $this->failureContent = $failureContent;

        if (!str_starts_with($errorCode, '00')) {
            if (is_null($errorMode)) {
                $errorMode = $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }

            $message= 'CQLSTATE[' . $errorCode . '] ' . ($failureContent['message'] ?? '');
            if (!empty($failureContent['code'])) {
                $message .= ' (' . $failureContent['code'] . ')';
            }
            if ($errorMode === PDO::ERRMODE_EXCEPTION) {
                throw new PDOException($message);
            } elseif ($errorMode === PDO::ERRMODE_WARNING) {
                trigger_error($message, E_WARNING);
            }
        }
    }
}
