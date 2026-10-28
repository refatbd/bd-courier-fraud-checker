Reject RedX static HTTP 400 dummy responses and surface accurate offline/unavailable status.

- Reject RedX HTTP 400 responses in `RedxResult::accepts()`. RedX's upstream reverse-proxy returns an invariant static boilerplate (`totalParcels: 10, deliveredParcels: 6`) on HTTP 400 regardless of the phone number queried. Only genuine HTTP 2xx success statuses with valid parcel counts are accepted.
- When RedX returns HTTP 400 or 503, report `status: false`, state `unavailable`, and user-friendly error message indicating the RedX customer lookup service is currently unavailable or offline.
- Test runner and regression suites updated to run cleanly without requiring dev-dependency assertion packages in production host environments.
