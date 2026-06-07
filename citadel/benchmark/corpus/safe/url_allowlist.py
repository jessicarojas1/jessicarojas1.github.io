import requests
ALLOWED = {"api.example.com"}
def fetch(host, path):
    if host not in ALLOWED: raise ValueError("blocked")
    return requests.get(f"https://{host}/{path}").text
