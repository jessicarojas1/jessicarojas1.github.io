import hashlib, hmac
def store(pw, salt): return hashlib.sha256(salt + pw.encode()).hexdigest()
