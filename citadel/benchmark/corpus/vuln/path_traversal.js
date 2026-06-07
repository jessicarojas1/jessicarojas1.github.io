const fs = require('fs');
app.get('/f', (req, res) => res.send(fs.readFileSync('/data/' + req.query.name)));
