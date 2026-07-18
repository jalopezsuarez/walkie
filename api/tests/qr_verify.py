#!/usr/bin/env python3
"""
Verifies the pure-PHP QR encoder (api/src/Shared/QrCode.php) module-for-module
against the reference `qrcode` library, forcing byte mode + version + mask on
both sides so the comparison is deterministic.

    pip install qrcode
    python3 api/tests/qr_verify.py

Expected output: "272 tests, 0 mismatches".
"""
import subprocess, sys
import qrcode
from qrcode.util import QRData, MODE_8BIT_BYTE
from qrcode.constants import ERROR_CORRECT_L, ERROR_CORRECT_M, ERROR_CORRECT_Q, ERROR_CORRECT_H

EC = {'L': ERROR_CORRECT_L, 'M': ERROR_CORRECT_M, 'Q': ERROR_CORRECT_Q, 'H': ERROR_CORRECT_H}
QR_PHP = 'api/src/Shared/QrCode.php'


def php_str(s):
    return '"' + s.replace('\\', '\\\\').replace('"', '\\"') + '"'


def php_matrix(data, ec, version, mask):
    code = (f"require '{QR_PHP}';"
            f"use Walkie\\Shared\\QrCode;"
            f"$m=QrCode::build({php_str(data)}, \"{ec}\", {version}, {mask});"
            f"foreach($m as $r){{foreach($r as $v){{echo $v?'1':'0';}}echo \"\\n\";}}")
    out = subprocess.run(['php', '-r', code], capture_output=True, text=True)
    if out.returncode != 0:
        print("PHP ERR:", out.stderr)
        sys.exit(1)
    return out.stdout.strip().split('\n')


def py_matrix(data, ec, version, mask):
    qr = qrcode.QRCode(version=version, error_correction=EC[ec], box_size=1, border=0, mask_pattern=mask)
    qr.add_data(QRData(data.encode(), mode=MODE_8BIT_BYTE))
    qr.make(fit=False)
    return [''.join('1' if c else '0' for c in row) for row in qr.get_matrix()]


def main():
    fails = tests = 0
    cases = [
        ('walkie', 'M'),
        ('https://walkie.howto.rocks/web/#p=AbC-123_xyz', 'M'),
        ('a' * 40, 'L'),
        ('X' * 60, 'Q'),
        ('token-Ab_9' * 8, 'H'),
    ]
    for data, ec in cases:
        for version in range(1, 11):
            try:
                py_matrix(data, ec, version, 0)
            except Exception:
                continue
            for mask in range(8):
                tests += 1
                if php_matrix(data, ec, version, mask) != py_matrix(data, ec, version, mask):
                    fails += 1
                    print(f"MISMATCH data={data[:20]!r} ec={ec} v={version} mask={mask}")
    print(f"{tests} tests, {fails} mismatches")
    sys.exit(1 if fails else 0)


if __name__ == '__main__':
    main()
