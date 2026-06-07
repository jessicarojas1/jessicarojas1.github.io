const { exec } = require('child_process');
function archive(dir) { exec('tar -czf out.tgz ' + dir); }
