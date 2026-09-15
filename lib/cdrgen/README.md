# Bundled CDRgen generation core

Concurrency Count bundles the side-effect-free generation dependency closure from [CDRgen](https://github.com/kierknoby/cdrgen).

- CDRgen version: 1.1.0
- Exact imported upstream revision: `e8f45d82163b081196efb82219751ce66b65cca4`
- Upstream internal base revision (`CdrGen\Version::BASE_REVISION`): `f3dcc9f0af7fcfb428f840004856d858d6de8a4c`
- Licence: MIT
- Concurrency Count integration boundary: `Services/CdrgenAdapter.php`

Bundled upstream files are `LICENSE`, `src/autoload.php`, `src/Version.php`, `src/GenerationRequest.php`, `src/GenerationResult.php`, `src/Generator.php`, `src/TrafficProfile.php`, `src/TrafficModel.php`, `src/TrunkProfiler.php`, and the three files under `src/Random/`. They are byte-for-byte copies from the revision above. `SHA256SUMS` provides deterministic verification. Concurrency Count-specific inventory translation, PJSIP policy, database schema adaptation, insertion, safety, calculation, audit, and cleanup remain outside the imported source.

To update the dependency, select a reviewed upstream release/revision, replace these upstream files, update the import revision and hashes, run applicable upstream core tests, `tests/CdrgenBundleIntegrityTest.php`, `tests/CdrgenAdapterTest.php`, and the full Concurrency Count regression suite, then inspect the complete diff and smoke-test representative PBXs before release.
