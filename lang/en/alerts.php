<?php

/*
 * Warning strings.
 *
 * Provisional wording. Hydromet has not approved a national event-code
 * catalogue, severity presentation or publication rules
 * (docs/08-hydromet-input-checklist.md, section 3), so the vocabulary below is
 * CAP's own and the labels are descriptive rather than official.
 */

return [
    'navigation_group' => 'Warnings',
    'message' => 'Warning message',
    'messages' => 'Warning messages',
    'read_only_notice' => 'Imported warning messages. The message history is written by the source import and cannot be edited here.',
    'not_supplied' => 'Not supplied',

    'fields' => [
        'identifier' => 'Identifier',
        'source' => 'Source',
        'sender' => 'Sender',
        'status' => 'Status',
        'message_type' => 'Message type',
        'scope' => 'Scope',
        'event_code' => 'Event code',
        'severity' => 'Severity',
        'urgency' => 'Urgency',
        'certainty' => 'Certainty',
        'categories' => 'Categories',
        'references' => 'References',
        'parameters' => 'Source parameters',
        'sent_at' => 'Sent',
        'effective_at' => 'Effective from',
        'onset_at' => 'Expected onset',
        'expires_at' => 'Expires',
        'headline' => 'Headline',
        'description' => 'Description',
        'instruction' => 'Instruction',
        'areas' => 'Affected areas',
        'area_count' => 'Areas',
        'geocodes' => 'Geocodes',
        'geometry' => 'Geometry',
        'superseded_at' => 'Superseded',
        'superseded_by' => 'Superseded by',
        'imported_at' => 'Imported',
        'lifecycle' => 'Lifecycle',
    ],

    'sections' => [
        'identity' => 'Identity',
        'classification' => 'Classification',
        'validity' => 'Validity',
        'content' => 'Public text',
        'areas' => 'Affected areas',
        'provenance' => 'Provenance',
    ],

    'lifecycle' => [
        'active' => 'In force',
        'scheduled' => 'Scheduled',
        'superseded' => 'Superseded',
        'expired' => 'Expired',
        'withheld' => 'Not published',
    ],

    'statuses' => [
        'Actual' => 'Actual',
        'Exercise' => 'Exercise',
        'System' => 'System',
        'Test' => 'Test',
        'Draft' => 'Draft',
    ],

    'message_types' => [
        'Alert' => 'Alert',
        'Update' => 'Update',
        'Cancel' => 'Cancel',
        'Ack' => 'Acknowledgement',
        'Error' => 'Error',
    ],

    'scopes' => [
        'Public' => 'Public',
        'Restricted' => 'Restricted',
        'Private' => 'Private',
    ],

    'severities' => [
        'Extreme' => 'Extreme',
        'Severe' => 'Severe',
        'Moderate' => 'Moderate',
        'Minor' => 'Minor',
        'Unknown' => 'Unknown',
    ],

    'urgencies' => [
        'Immediate' => 'Immediate',
        'Expected' => 'Expected',
        'Future' => 'Future',
        'Past' => 'Past',
        'Unknown' => 'Unknown',
    ],

    'certainties' => [
        'Observed' => 'Observed',
        'Likely' => 'Likely',
        'Possible' => 'Possible',
        'Unlikely' => 'Unlikely',
        'Unknown' => 'Unknown',
    ],
];
