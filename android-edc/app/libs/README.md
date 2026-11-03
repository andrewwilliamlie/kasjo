Put the PAX NeptuneLite SDK jar here (for example `NeptuneLiteApi_V4.05.00_*.jar`) when building on your own computer.
It is not committed: the repo is public and the SDK is licensed to HausJo. GitHub Actions gets it from the secret `PAX_SDK_JAR_B64`.
Without the jar the app still builds (against `pax-stub`), but the built-in printer reports "tidak tersedia".
