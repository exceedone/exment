<?php

namespace Exceedone\Exment\Services\Storage;

/**
 * Production ConversionAvailability: conversion is not usable. Every internal orchestration
 * entry (provision, transitions, backfill, verification) refuses while this is bound.
 * Each code names what must exist first (02 §15.7-§15.10, §15.14).
 */
class UnavailableConversion implements ConversionAvailability
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function missing(): array
    {
        return [
            'integration_verification',   // blocks 1-4 have not been run (migrations, tests, drivers)
            'raw_writer_enforcement',     // raw SQL / plugin value-> / old code: attestation only (§15.8)
            'unresolved_query_semantics', // D-3 empty, D-6 string collation, lookup, unique, contains (§15.15)
            'automatic_indexes',          // L4 / D-8
            'operator_interface',         // no route/command/UI (L5 confirmation screen) by design
        ];
    }
}
