# Example (in a comment, not executed):
#   cur.execute("SELECT * FROM users WHERE id = " + uid)
def get_user(db, uid):
    return db.query(uid)
