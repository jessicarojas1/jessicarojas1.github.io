def lookup(cur, name):
    cur.execute(f"SELECT * FROM accounts WHERE name = '{name}'")
