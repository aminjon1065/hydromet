<?php

namespace App\Support\Canonical;

/**
 * Stable reason codes for rows the portal refused to import.
 *
 * The codes are part of the operator-facing contract: they are logged, shown in
 * import output and will later be stored with rejected rows. They never carry
 * provider payloads, credentials or stack traces.
 *
 * One vocabulary is shared by every import so an operator reads the same code
 * for the same kind of problem, wherever it was found.
 */
enum RejectionReason: string
{
    /** The row is not an object, or a required field is absent. */
    case MalformedRow = 'malformed_row';

    /** A field is present but not of the canonical type. */
    case InvalidFieldType = 'invalid_field_type';

    /** A field uses a value outside the canonical enumeration. */
    case UnknownEnumValue = 'unknown_enum_value';

    case LatitudeOutOfRange = 'latitude_out_of_range';

    case LongitudeOutOfRange = 'longitude_out_of_range';

    /** The timezone is not a known IANA identifier. */
    case UnknownTimezone = 'unknown_timezone';

    /** The station references a parameter code that is not in the catalogue. */
    case UnknownParameterCode = 'unknown_parameter_code';

    /** The declared public precision is outside the supported range. */
    case PrecisionOutOfRange = 'precision_out_of_range';

    /** plausible_min is greater than plausible_max. */
    case ImplausibleBounds = 'implausible_bounds';

    /** Two rows in the same batch claim the same identity. */
    case DuplicateInBatch = 'duplicate_in_batch';

    /** The row lost a uniqueness race or violated a database constraint. */
    case PersistenceConflict = 'persistence_conflict';

    /** The measurement names a station that is not in the registry. */
    case UnknownStation = 'unknown_station';

    /** The measurement's unit is not the parameter's canonical unit. */
    case UnitMismatch = 'unit_mismatch';

    /** The revision number is below the documented starting point of 1. */
    case InvalidRevision = 'invalid_revision';

    /** A revision older than the stored one; the stored row stays effective. */
    case StaleRevision = 'stale_revision';

    /** The stored revision number, restated with a different value or quality. */
    case RevisionConflict = 'revision_conflict';

    /** A timestamp carried more fractional digits than the portal can store. */
    case UnsupportedTimestampPrecision = 'unsupported_timestamp_precision';

    /** Quality `missing` was declared together with a value. */
    case MissingRequiresNullValue = 'missing_requires_null_value';

    /** No value was supplied, but the quality claims a reading was taken. */
    case NullValueRequiresMissingQuality = 'null_value_requires_missing_quality';

    /** A source batch claimed a manually entered reading. */
    case ManualEntryNotSupported = 'manual_entry_not_supported';

    /** A warning area used a geometry the portal cannot draw. */
    case UnsupportedGeometry = 'unsupported_geometry';

    /** A warning's validity window is impossible: it ends before it starts. */
    case InvalidValidityWindow = 'invalid_validity_window';

    /** A warning carries no affected area, so nothing could be shown. */
    case MissingAffectedArea = 'missing_affected_area';

    /** An Update or Cancel names no message to supersede. */
    case MissingReference = 'missing_reference';

    /** A required translation was absent, and no fallback rule is approved. */
    case IncompleteTranslation = 'incomplete_translation';

    /**
     * A stored identifier was resent with different content. CAP corrects a
     * warning with a new identifier, so this is a provider or feed fault and
     * the stored message is kept untouched.
     */
    case IdentifierConflict = 'identifier_conflict';
}
