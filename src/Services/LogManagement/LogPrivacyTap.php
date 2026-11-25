<?php

namespace Exceedone\Exment\Services\LogManagement;

/**
 * The tap of the file channels of the application log (ISS02839, part 4): while the logs keep no
 * input (LogPrivacy::storeInput() off), a message and the exception of its context lose the mail
 * addresses and the quoted values of SQL (LogPrivacy::maskText()).
 */
class LogPrivacyTap
{
    /**
     * @param mixed $logger the logger of the channel (Illuminate\Log\Logger)
     */
    public function __invoke($logger): void
    {
        $monolog = $logger instanceof \Illuminate\Log\Logger ? $logger->getLogger() : $logger;
        if (!($monolog instanceof \Monolog\Logger)) {
            return;
        }
        $monolog->pushProcessor(function ($record) {
            return static::process($record);
        });
    }

    /**
     * @param mixed $record a Monolog LogRecord (Monolog 3) or an array (Monolog 2)
     * @return mixed
     */
    public static function process($record)
    {
        if (LogPrivacy::storeInput()) {
            return $record;
        }
        $message = is_array($record) ? strval($record['message'] ?? '') : strval($record->message);
        $context = is_array($record) ? (array) ($record['context'] ?? []) : (array) $record->context;
        $masked = LogPrivacy::maskText($message);
        foreach ($context as $key => $value) {
            if ($value instanceof \Throwable) {
                // the trace stays, the messages lose the data
                $context[$key] = get_class($value) . ': ' . LogPrivacy::maskText(strval($value));
            } elseif (is_string($value)) {
                $context[$key] = LogPrivacy::maskText($value);
            }
        }
        if (is_array($record)) {
            $record['message'] = $masked;
            $record['context'] = $context;
            return $record;
        }
        return $record->with(message: $masked, context: $context);
    }
}
