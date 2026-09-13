<?php

declare(strict_types=1);

namespace Bpmore\Scholarly\Fetching;

/**
 * Why a source produced nothing.
 *
 * Deliberately coarse. The detail string carries specifics; these are the
 * distinctions a reader has to act on differently, and nothing finer earns a
 * case.
 */
enum Reason: string
{
    /** No credential or no identifier for this researcher, so it never ran. */
    case NotConfigured = 'not configured';

    /** The remote refused or failed. Retrying later may work. */
    case TransportFailed = 'transport failed';

    /** The remote answered in a shape this version does not understand. */
    case UnexpectedResponse = 'unexpected response';

    /** Refused locally before any request, for example a missing contact email. */
    case RefusedLocally = 'refused locally';
}
