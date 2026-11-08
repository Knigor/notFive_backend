#!/usr/bin/env python3
"""Run Go tests without copying test files into production packages."""

import json
from pathlib import Path
import subprocess
import sys
import tempfile


ROOT = Path(__file__).resolve().parents[1]
TESTDATA = ROOT / "tests" / "testdata"


def main():
    # Go needs white-box tests in the package they test. The build overlay adds
    # virtual files there; testdata keeps the physical files out of ./... walks.
    replacements = {}
    for source in sorted(TESTDATA.rglob("*_test.go")):
        target = ROOT / "internal" / source.relative_to(TESTDATA)
        if not target.parent.is_dir() or target.exists():
            raise ValueError(f"Invalid Go test destination: {target}")
        replacements[str(target)] = str(source)
    if not replacements:
        raise ValueError("No Go tests found in tests/testdata")

    with tempfile.TemporaryDirectory(prefix="notfive-tests-") as temporary:
        overlay = Path(temporary) / "overlay.json"
        overlay.write_text(json.dumps({"Replace": replacements}), encoding="utf-8")
        commands = [
            ["go", "vet", "-overlay", str(overlay), "./..."],
            ["go", "test", "-overlay", str(overlay), "-race", "./..."],
        ]
        for command in commands:
            subprocess.run(command, cwd=ROOT, check=True)


if __name__ == "__main__":
    try:
        main()
    except subprocess.CalledProcessError as error:
        sys.exit(error.returncode if error.returncode > 0 else 1)
    except (OSError, ValueError) as error:
        sys.exit(str(error))
