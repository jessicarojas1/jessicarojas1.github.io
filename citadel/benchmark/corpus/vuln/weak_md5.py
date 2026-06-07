import hashlib
def store(pw): return hashlib.md5(pw.encode()).hexdigest()
