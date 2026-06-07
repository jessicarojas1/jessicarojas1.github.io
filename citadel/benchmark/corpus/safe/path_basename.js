const fs = require('fs'); const path = require('path');
app.get('/f', (req, res) => res.send(fs.readFileSync(path.join('/data', path.basename(req.query.name)))));
