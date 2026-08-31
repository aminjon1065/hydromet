<?php

namespace App\Domain\Stations\Enums;

/**
 * Stable reason codes for rows the portal refused to import.
 *
 * The codes are part of the operator-facing contract: they are logged, shown in
 * import output and will later be stored with rejected rows. They never carry
 * provider payloads, credentials or stack traces.
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
}
