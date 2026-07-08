#!/usr/bin/env python3
"""Independent BIP32 non-hardened derivation to cross-check the plugin's HdHelper.
Pure stdlib: secp256k1 point math, base58check, hash160."""
import hashlib, hmac

P = 0xFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F
N = 0xFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141
Gx = 0x79BE667EF9DCBBAC55A06295CE870B07029BFCDB2DCE28D959F2815B16F81798
Gy = 0x483ADA7726A3C4655DA4FBFC0E1108A8FD17B448A68554199C47D08FFB10D4B8
B58 = "123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz"

def inv(a, m): return pow(a, -1, m)

def ec_add(p1, p2):
    if p1 is None: return p2
    if p2 is None: return p1
    x1, y1 = p1; x2, y2 = p2
    if x1 == x2 and (y1 + y2) % P == 0: return None
    if p1 == p2:
        lam = (3 * x1 * x1) * inv(2 * y1, P) % P
    else:
        lam = (y2 - y1) * inv(x2 - x1, P) % P
    x3 = (lam * lam - x1 - x2) % P
    y3 = (lam * (x1 - x3) - y1) % P
    return (x3, y3)

def ec_mul(k, pt):
    r = None
    while k:
        if k & 1: r = ec_add(r, pt)
        pt = ec_add(pt, pt)
        k >>= 1
    return r

def b58decode(s):
    n = 0
    for c in s: n = n * 58 + B58.index(c)
    raw = n.to_bytes((n.bit_length() + 7) // 8, "big")
    pad = len(s) - len(s.lstrip("1"))
    return b"\x00" * pad + raw

def b58encode(b):
    n = int.from_bytes(b, "big")
    out = ""
    while n: n, r = divmod(n, 58); out = B58[r] + out
    pad = len(b) - len(b.lstrip(b"\x00"))
    return "1" * pad + out

def decompress(pk33):
    x = int.from_bytes(pk33[1:], "big")
    y2 = (pow(x, 3, P) + 7) % P
    y = pow(y2, (P + 1) // 4, P)
    if (y % 2) != (pk33[0] % 2): y = P - y
    return (x, y)

def compress(pt):
    x, y = pt
    return bytes([2 + (y & 1)]) + x.to_bytes(32, "big")

def ckd_pub(pk33, chaincode, i):
    I = hmac.new(chaincode, pk33 + i.to_bytes(4, "big"), hashlib.sha512).digest()
    il = int.from_bytes(I[:32], "big")
    assert il < N
    child = ec_add(ec_mul(il, (Gx, Gy)), decompress(pk33))
    return compress(child), I[32:]

def hash160(b):
    return hashlib.new("ripemd160", hashlib.sha256(b).digest()).digest()

def p2pkh(pk33):
    payload = b"\x00" + hash160(pk33)
    check = hashlib.sha256(hashlib.sha256(payload).digest()).digest()[:4]
    return b58encode(payload + check)

xpub = "xpub6ASuArnXKPbfEwhqN6e3mwBcDTgzisQN1wXN9BJcM47sSikHjJf3UFHKkNAWbWMiGj7Wf5uMash7SyYq527Hqck2AxYysAA7xmALppuCkwQ"
raw = b58decode(xpub)
payload, check = raw[:-4], raw[-4:]
assert hashlib.sha256(hashlib.sha256(payload).digest()).digest()[:4] == check, "xpub checksum"
chaincode, pk = payload[13:45], payload[45:78]

for idx in (0, 1, 2):
    k, c = ckd_pub(pk, chaincode, 0)      # external chain (change=0)
    k, c = ckd_pub(k, c, idx)             # address index
    print(f"python m/0/{idx} => {p2pkh(k)}")
