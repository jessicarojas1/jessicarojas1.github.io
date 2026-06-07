const { execFile } = require('child_process');
function archive(dir) { execFile('tar', ['-czf', 'out.tgz', dir]); }
