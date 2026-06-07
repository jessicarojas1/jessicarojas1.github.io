import sqlite3
def get_user(db, uid):
    cur = db.cursor()
    cur.execute("SELECT * FROM users WHERE id = " + uid)
    return cur.fetchone()
