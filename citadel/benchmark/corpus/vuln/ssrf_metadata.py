import requests
def creds(): return requests.get("http://169.254.169.254/latest/meta-data/iam/").text
