Fix RedX customer lookups discarded despite a valid success envelope.

- Accept RedX's observed HTTP 400 anomaly only when the body explicitly has `code=200`, `isError=false`, and both parcel counts are non-negative integers with delivered <= total. Genuine 400 errors, malformed bodies, auth failures, throttling and server errors remain failures. Valid legacy 2xx count responses remain supported.
- Avoid retrying successful-envelope 400 responses. Only a stale 401 token earns one refresh. Failure messages are non-empty and sanitized; HTTP status and connection state are retained in metadata.
- Normalize delivered and unsuccessful percentages from explicit counts so counts and rates agree. Preserve RedX's original returnPercentage separately as `providerReturnPercentage`, since the observed field did not agree with the explicit counts. Empty histories have null percentages.
- Paperfly zero-order responses expose null delivery/return rates instead of implying a measured 0% success rate.
- Add fake-HTTP regressions for envelope acceptance/rejection, invalid counts, auth refresh bounds, throttling, network redaction and empty history. No live credentials or recipient data appear in fixtures.

Consumer note: honor null rates and render empty history separately from failed access. Do not interpret `meta.http_status=400` alone as a failure when `status=true` and `meta.success_envelope_compatibility=true`. The compatibility path is specific to the validated RedX response contract.
