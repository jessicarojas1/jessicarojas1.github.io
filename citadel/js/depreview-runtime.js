/* CITADEL — Dependency Review: Runtime & Infrastructure discovery.
 * Pure, offline, content-based analysis of repo entries.
 * Exports CITADEL.depreviewRuntime.analyze(entries) per the shared data contract.
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  // Cap regex scanning on very large files.
  const MAX_SCAN = 200 * 1024;

  function textEntries(entries) {
    if (!Array.isArray(entries)) return [];
    return entries.filter(function (e) {
      return e && e.content && !e.isBinary && typeof e.content === 'string';
    });
  }

  function slice(content) {
    if (!content) return '';
    return content.length > MAX_SCAN ? content.slice(0, MAX_SCAN) : content;
  }

  function base(path) {
    return String(path || '').split('/').pop().toLowerCase();
  }

  function lc(path) {
    return String(path || '').toLowerCase();
  }

  function safeJson(content) {
    try {
      return JSON.parse(content);
    } catch (e) {
      return null;
    }
  }

  function uniqBy(arr, keyFn) {
    const seen = {};
    const out = [];
    arr.forEach(function (item) {
      const k = keyFn(item);
      if (seen[k]) return;
      seen[k] = true;
      out.push(item);
    });
    return out;
  }

  // ---------------------------------------------------------------------------
  // STACK
  // ---------------------------------------------------------------------------

  const ARCHIVE_EXT = /\.(zip|tar|gz|tgz|bz2|xz|7z|rar|jar|war|ear|class|exe|dll|so|dylib|o|a|bin|png|jpg|jpeg|gif|svg|ico|pdf|woff2?|ttf|eot|mp4|mp3|wav)$/i;

  function detectLanguages(entries) {
    const counts = {};
    entries.forEach(function (e) {
      if (!e || !e.lang || e.isBinary) return;
      if (ARCHIVE_EXT.test(e.path || '')) return;
      const name = e.lang;
      if (!name || name === 'Other' || name === 'Binary') return;
      counts[name] = (counts[name] || 0) + 1;
    });
    const list = Object.keys(counts).map(function (name) {
      return { name: name, primary: false, files: counts[name] };
    });
    list.sort(function (a, b) { return b.files - a.files; });
    if (list.length) list[0].primary = true;
    return list;
  }

  function pushUnique(list, keyFn, item) {
    const k = keyFn(item);
    for (let i = 0; i < list.length; i++) {
      if (keyFn(list[i]) === k) return;
    }
    list.push(item);
  }

  function collectManifests(entries) {
    const out = { pkgJson: [], requirements: [], pyproject: [], goMod: [], cargo: [], pom: [], gradle: [], composer: [], gemfile: [], csproj: [], globalJson: [] };
    entries.forEach(function (e) {
      const b = base(e.path);
      if (b === 'package.json') out.pkgJson.push(e);
      else if (b === 'requirements.txt' || /requirements.*\.txt$/.test(b)) out.requirements.push(e);
      else if (b === 'pyproject.toml') out.pyproject.push(e);
      else if (b === 'go.mod') out.goMod.push(e);
      else if (b === 'cargo.toml') out.cargo.push(e);
      else if (b === 'pom.xml') out.pom.push(e);
      else if (b === 'build.gradle' || b === 'build.gradle.kts') out.gradle.push(e);
      else if (b === 'composer.json') out.composer.push(e);
      else if (b === 'gemfile') out.gemfile.push(e);
      else if (/\.csproj$/.test(b) || /\.fsproj$/.test(b)) out.csproj.push(e);
      else if (b === 'global.json') out.globalJson.push(e);
    });
    return out;
  }

  function allDeps(pkg) {
    const d = {};
    ['dependencies', 'devDependencies', 'peerDependencies', 'optionalDependencies'].forEach(function (k) {
      if (pkg && pkg[k] && typeof pkg[k] === 'object') {
        Object.keys(pkg[k]).forEach(function (name) { d[name] = pkg[k][name]; });
      }
    });
    return d;
  }

  function cleanVer(v) {
    if (!v || typeof v !== 'string') return null;
    const m = v.match(/(\d+(?:\.\d+){0,2}(?:[-.][\w.]+)?)/);
    return m ? m[1] : null;
  }

  function detectFrameworks(entries, manifests) {
    const out = [];
    const add = function (name, version, confidence, evidence) {
      pushUnique(out, function (f) { return f.name; }, { name: name, version: version || null, confidence: confidence, evidence: evidence });
    };

    // From package.json deps (high confidence + version).
    manifests.pkgJson.forEach(function (e) {
      const pkg = safeJson(e.content);
      if (!pkg) return;
      const deps = allDeps(pkg);
      const map = {
        'express': 'Express', 'next': 'Next.js', 'react': 'React', 'vue': 'Vue',
        '@angular/core': 'Angular', '@nestjs/core': 'NestJS', 'koa': 'Koa',
        'fastify': 'Fastify', 'svelte': 'Svelte', '@sveltejs/kit': 'SvelteKit',
        'nuxt': 'Nuxt', '@remix-run/react': 'Remix'
      };
      Object.keys(map).forEach(function (dep) {
        if (deps[dep] != null) add(map[dep], cleanVer(deps[dep]), 'high', dep + ' in package.json');
      });
    });

    // Python manifests.
    function pyText() {
      let t = '';
      manifests.requirements.forEach(function (e) { t += '\n' + e.content; });
      manifests.pyproject.forEach(function (e) { t += '\n' + e.content; });
      return t;
    }
    const py = pyText();
    if (py) {
      const pyMap = [
        [/^\s*django\b/im, 'Django'], [/^\s*flask\b/im, 'Flask'],
        [/^\s*fastapi\b/im, 'FastAPI'], [/^\s*tornado\b/im, 'Tornado'],
        [/^\s*pyramid\b/im, 'Pyramid'], [/^\s*aiohttp\b/im, 'aiohttp']
      ];
      pyMap.forEach(function (pair) {
        const m = py.match(pair[0]);
        if (m) {
          const verM = py.match(new RegExp(pair[1].split(/[^a-z]/i)[0] + '[=<>~!]+\\s*([\\d.]+)', 'i'));
          add(pair[1], verM ? verM[1] : null, 'high', 'Python manifest');
        }
      });
    }

    // Go.
    const goText = manifests.goMod.map(function (e) { return e.content; }).join('\n');
    if (/gin-gonic\/gin/.test(goText)) add('Gin', cleanVer((goText.match(/gin-gonic\/gin\s+v([\d.]+)/) || [])[1]), 'high', 'go.mod');
    if (/labstack\/echo/.test(goText)) add('Echo', cleanVer((goText.match(/labstack\/echo[^\s]*\s+v([\d.]+)/) || [])[1]), 'high', 'go.mod');
    if (/gofiber\/fiber/.test(goText)) add('Fiber', null, 'high', 'go.mod');

    // Rust.
    const cargoText = manifests.cargo.map(function (e) { return e.content; }).join('\n');
    if (/^\s*actix-web\b/im.test(cargoText)) add('Actix', null, 'high', 'Cargo.toml');
    if (/^\s*rocket\b/im.test(cargoText)) add('Rocket', null, 'high', 'Cargo.toml');
    if (/^\s*axum\b/im.test(cargoText)) add('Axum', null, 'high', 'Cargo.toml');

    // PHP.
    manifests.composer.forEach(function (e) {
      const pkg = safeJson(e.content);
      if (!pkg) return;
      const deps = Object.assign({}, pkg.require || {}, pkg['require-dev'] || {});
      if (deps['laravel/framework']) add('Laravel', cleanVer(deps['laravel/framework']), 'high', 'composer.json');
      if (Object.keys(deps).some(function (k) { return /^symfony\//.test(k); })) add('Symfony', null, 'high', 'composer.json');
    });

    // Ruby.
    const gemText = manifests.gemfile.map(function (e) { return e.content; }).join('\n');
    if (/gem\s+['"]rails['"]/.test(gemText)) add('Rails', cleanVer((gemText.match(/gem\s+['"]rails['"][^\n]*?([\d.]+)/) || [])[1]), 'high', 'Gemfile');
    if (/gem\s+['"]sinatra['"]/.test(gemText)) add('Sinatra', null, 'high', 'Gemfile');

    // JVM.
    const pomText = manifests.pom.map(function (e) { return e.content; }).join('\n');
    const gradleText = manifests.gradle.map(function (e) { return e.content; }).join('\n');
    const jvmText = pomText + '\n' + gradleText;
    if (/spring-boot/.test(jvmText)) add('Spring Boot', cleanVer((jvmText.match(/spring-boot[^<\n]*?([\d.]+)/) || [])[1]), 'high', 'Maven/Gradle');
    else if (/springframework/.test(jvmText)) add('Spring', null, 'high', 'Maven/Gradle');
    if (/io\.quarkus/.test(jvmText)) add('Quarkus', null, 'high', 'Maven/Gradle');
    if (/micronaut/.test(jvmText)) add('Micronaut', null, 'high', 'Maven/Gradle');

    // .NET.
    const csprojText = manifests.csproj.map(function (e) { return e.content; }).join('\n');
    if (/Microsoft\.AspNetCore|Sdk="Microsoft\.NET\.Sdk\.Web"/.test(csprojText)) add('ASP.NET Core', null, 'high', '.csproj');

    // Import-based fallback (medium/low) for JS frameworks not in a manifest.
    let importHits = { express: false, react: false, vue: false, angular: false, flask: false, django: false };
    entries.forEach(function (e) {
      const c = slice(e.content);
      if (!c) return;
      if (!importHits.express && /require\(['"]express['"]\)|from ['"]express['"]/.test(c)) importHits.express = true;
      if (!importHits.react && /from ['"]react['"]/.test(c)) importHits.react = true;
      if (!importHits.vue && /from ['"]vue['"]/.test(c)) importHits.vue = true;
      if (!importHits.flask && /from flask import|import flask\b/.test(c)) importHits.flask = true;
      if (!importHits.django && /from django\b/.test(c)) importHits.django = true;
    });
    if (importHits.express) add('Express', null, 'medium', 'import in source');
    if (importHits.react) add('React', null, 'medium', 'import in source');
    if (importHits.vue) add('Vue', null, 'medium', 'import in source');
    if (importHits.flask) add('Flask', null, 'medium', 'import in source');
    if (importHits.django) add('Django', null, 'medium', 'import in source');

    return out;
  }

  function detectRuntimes(entries, manifests) {
    const out = [];
    const add = function (name, version, evidence) {
      pushUnique(out, function (r) { return r.name; }, { name: name, version: version || null, evidence: evidence });
    };

    // Node from package.json engines, .nvmrc.
    manifests.pkgJson.forEach(function (e) {
      const pkg = safeJson(e.content);
      if (!pkg) return;
      let ver = null;
      if (pkg.engines && pkg.engines.node) ver = cleanVer(pkg.engines.node);
      add('Node.js', ver, ver ? 'engines.node in package.json' : 'package.json present');
    });
    entries.forEach(function (e) {
      const b = base(e.path);
      if (b === '.nvmrc') add('Node.js', cleanVer(e.content), '.nvmrc');
      if (b === 'runtime.txt') {
        const m = (e.content || '').match(/python-?([\d.]+)/i);
        if (m) add('Python', m[1], 'runtime.txt');
      }
      if (b === '.python-version') add('Python', cleanVer(e.content), '.python-version');
    });

    // Python from manifests.
    if (manifests.requirements.length || manifests.pyproject.length) {
      let ver = null;
      manifests.pyproject.forEach(function (e) {
        const m = (e.content || '').match(/python_requires?\s*=\s*['"][^\d]*([\d.]+)/i) || (e.content || '').match(/python\s*=\s*['"][^\d]*([\d.]+)/i);
        if (m) ver = m[1];
      });
      add('Python', ver, 'Python manifest');
    }

    // Go.
    manifests.goMod.forEach(function (e) {
      const m = (e.content || '').match(/^\s*go\s+([\d.]+)/m);
      add('Go', m ? m[1] : null, 'go.mod');
    });

    // Rust.
    if (manifests.cargo.length) add('Rust', null, 'Cargo.toml');

    // JVM.
    if (manifests.pom.length || manifests.gradle.length) {
      let ver = null;
      manifests.pom.forEach(function (e) {
        const m = (e.content || '').match(/<(?:maven\.compiler\.(?:source|target)|java\.version)>([\d.]+)</);
        if (m) ver = m[1];
      });
      manifests.gradle.forEach(function (e) {
        const m = (e.content || '').match(/sourceCompatibility\s*=?\s*['"]?(?:JavaVersion\.VERSION_)?([\d._]+)/);
        if (m) ver = m[1].replace(/_/g, '.');
      });
      add('JVM', ver, 'Maven/Gradle');
    }

    // .NET.
    manifests.csproj.forEach(function (e) {
      const m = (e.content || '').match(/<TargetFramework[s]?>([^<]+)</);
      add('.NET', m ? cleanVer(m[1]) : null, '<TargetFramework>');
    });

    // Ruby.
    entries.forEach(function (e) {
      if (base(e.path) === '.ruby-version') add('Ruby', cleanVer(e.content), '.ruby-version');
    });
    if (manifests.gemfile.length) {
      let ver = null;
      manifests.gemfile.forEach(function (e) {
        const m = (e.content || '').match(/ruby\s+['"]([\d.]+)/);
        if (m) ver = m[1];
      });
      add('Ruby', ver, 'Gemfile');
    }

    // Dockerfile FROM tag.
    entries.forEach(function (e) {
      const b = base(e.path);
      if (b === 'dockerfile' || b.indexOf('dockerfile.') === 0) {
        const froms = (e.content || '').match(/^\s*FROM\s+([^\s]+)/gim) || [];
        froms.forEach(function (line) {
          const img = line.replace(/^\s*FROM\s+/i, '');
          if (/node:/i.test(img)) add('Node.js', cleanVer(img.split(':')[1]), 'Dockerfile FROM ' + img);
          else if (/python:/i.test(img)) add('Python', cleanVer(img.split(':')[1]), 'Dockerfile FROM ' + img);
          else if (/(openjdk|eclipse-temurin|amazoncorretto|adoptopenjdk):/i.test(img)) add('JVM', cleanVer(img.split(':')[1]), 'Dockerfile FROM ' + img);
          else if (/golang:/i.test(img)) add('Go', cleanVer(img.split(':')[1]), 'Dockerfile FROM ' + img);
          else if (/ruby:/i.test(img)) add('Ruby', cleanVer(img.split(':')[1]), 'Dockerfile FROM ' + img);
          else if (/(dotnet|aspnet|sdk):/i.test(img) && /dotnet|aspnet/i.test(img)) add('.NET', cleanVer(img.split(':')[1]), 'Dockerfile FROM ' + img);
        });
      }
    });

    return out;
  }

  function detectSdksCompilers(entries, manifests) {
    const sdks = [];
    const compilers = [];
    const addSdk = function (name, version, evidence) {
      pushUnique(sdks, function (s) { return s.name; }, { name: name, version: version || null, evidence: evidence });
    };
    const addComp = function (name, version, evidence) {
      pushUnique(compilers, function (s) { return s.name; }, { name: name, version: version || null, evidence: evidence });
    };

    manifests.globalJson.forEach(function (e) {
      const j = safeJson(e.content);
      let ver = null;
      if (j && j.sdk && j.sdk.version) ver = j.sdk.version;
      addSdk('.NET SDK', ver, 'global.json');
    });

    entries.forEach(function (e) {
      const b = base(e.path);
      const c = slice(e.content);
      if (b === 'makefile' || b === 'gnumakefile' || /\.mk$/.test(b)) {
        if (/\bgcc\b/.test(c)) addComp('gcc', null, 'Makefile');
        if (/\bg\+\+\b/.test(c)) addComp('g++', null, 'Makefile');
        if (/\bclang\b/.test(c)) addComp('clang', null, 'Makefile');
        if (/\bjavac\b/.test(c)) addComp('javac', null, 'Makefile');
        if (/\bgo build\b/.test(c)) addComp('go', null, 'Makefile');
      }
      if (b === 'cmakelists.txt') {
        addComp('CMake', null, 'CMakeLists.txt');
      }
    });

    if (manifests.cargo.length) addComp('rustc', null, 'Cargo.toml');
    if (manifests.goMod.length) addComp('go', null, 'go.mod');

    return { sdks: sdks, compilers: compilers };
  }

  function detectPackageManagers(entries) {
    const out = [];
    const add = function (name, lockfile) {
      pushUnique(out, function (p) { return p.name; }, { name: name, lockfile: lockfile || null });
    };
    entries.forEach(function (e) {
      const b = base(e.path);
      if (b === 'package-lock.json') add('npm', e.path);
      else if (b === 'yarn.lock') add('yarn', e.path);
      else if (b === 'pnpm-lock.yaml') add('pnpm', e.path);
      else if (b === 'bun.lockb' || b === 'bun.lock') add('bun', e.path);
      else if (b === 'poetry.lock') add('poetry', e.path);
      else if (b === 'pipfile.lock') add('pipenv', e.path);
      else if (b === 'requirements.txt') add('pip', e.path);
      else if (b === 'go.sum') add('go modules', e.path);
      else if (b === 'cargo.lock') add('cargo', e.path);
      else if (b === 'composer.lock') add('composer', e.path);
      else if (b === 'gemfile.lock') add('bundler', e.path);
      else if (b === 'packages.lock.json') add('nuget', e.path);
      else if (b === 'gradle.lockfile') add('gradle', e.path);
    });
    return out;
  }

  function detectOsArchShells(entries) {
    const os = [];
    const arch = [];
    const shells = [];
    const addOs = function (name, evidence) { pushUnique(os, function (o) { return o.name; }, { name: name, evidence: evidence }); };
    const addArch = function (name, evidence) { pushUnique(arch, function (o) { return o.name; }, { name: name, evidence: evidence }); };
    const addShell = function (name, evidence) { pushUnique(shells, function (o) { return o.name; }, { name: name, evidence: evidence }); };

    entries.forEach(function (e) {
      const b = base(e.path);
      const c = slice(e.content);
      if (b === 'dockerfile' || b.indexOf('dockerfile.') === 0) {
        const from = (c.match(/^\s*FROM\s+([^\s]+)/im) || [])[1] || '';
        if (/alpine/i.test(from)) addOs('Alpine', 'Dockerfile FROM ' + from);
        else if (/debian|bullseye|bookworm|buster|slim/i.test(from)) addOs('Debian', 'Dockerfile FROM ' + from);
        else if (/ubuntu/i.test(from)) addOs('Ubuntu', 'Dockerfile FROM ' + from);
        else if (/(windows|nanoserver|servercore)/i.test(from)) addOs('Windows', 'Dockerfile FROM ' + from);
        const plat = c.match(/--platform[=\s]+([^\s]+)/i);
        if (plat) {
          if (/arm64|aarch64/i.test(plat[1])) addArch('arm64', 'Docker --platform');
          if (/amd64|x86_64/i.test(plat[1])) addArch('amd64', 'Docker --platform');
        }
      }
      if (/\.github\/workflows\//.test(lc(e.path))) {
        const runs = c.match(/runs-on:\s*([^\s\n]+)/gi) || [];
        runs.forEach(function (r) {
          if (/ubuntu/i.test(r)) addOs('Ubuntu', 'CI runner: ' + r.trim());
          else if (/windows/i.test(r)) addOs('Windows', 'CI runner: ' + r.trim());
          else if (/macos/i.test(r)) addOs('macOS', 'CI runner: ' + r.trim());
        });
      }
      // Shells from shebangs / script files.
      if (/^#!.*\b(bash)\b/m.test(c) || b.endsWith('.bash')) addShell('bash', 'shebang/script');
      else if (/^#!.*\/sh\b/m.test(c) || b.endsWith('.sh')) addShell('sh', 'shebang/script');
      if (/^#!.*\bpwsh\b/m.test(c) || b.endsWith('.ps1')) addShell('pwsh', 'shebang/script');
      if (/shell:\s*bash/i.test(c)) addShell('bash', 'CI shell directive');
      if (/shell:\s*pwsh/i.test(c)) addShell('pwsh', 'CI shell directive');
    });

    return { os: os, arch: arch, shells: shells };
  }

  function detectCloud(entries, manifests) {
    const found = {};
    const text = [];
    manifests.pkgJson.forEach(function (e) {
      const pkg = safeJson(e.content);
      if (pkg) text.push(Object.keys(allDeps(pkg)).join(' '));
    });
    manifests.requirements.forEach(function (e) { text.push(e.content); });
    manifests.pyproject.forEach(function (e) { text.push(e.content); });
    entries.forEach(function (e) {
      const c = slice(e.content);
      if (/\.tf$|\.bicep$/.test(lc(e.path)) || base(e.path) === 'serverless.yml') text.push(c);
    });
    const blob = text.join('\n');
    if (/@aws-sdk\/|\baws-sdk\b|\bboto3\b|\bbotocore\b|amazonaws\.com|provider ['"]aws['"]|AWS::/.test(blob)) found.AWS = true;
    if (/@azure\/|azure-storage|azure-identity|\.windows\.net|provider ['"]azurerm['"]|Microsoft\.[A-Z]/.test(blob)) found.Azure = true;
    if (/@google-cloud\/|google-cloud-|googleapis|provider ['"]google['"]|\bgcloud\b/.test(blob)) found.GCP = true;
    return Object.keys(found);
  }

  // ---------------------------------------------------------------------------
  // RUNTIME: services, databases, env vars, ports
  // ---------------------------------------------------------------------------

  function gatherDepNames(manifests) {
    const names = {};
    manifests.pkgJson.forEach(function (e) {
      const pkg = safeJson(e.content);
      if (pkg) Object.keys(allDeps(pkg)).forEach(function (n) { names[n.toLowerCase()] = true; });
    });
    const txt = [];
    manifests.requirements.forEach(function (e) { txt.push(e.content); });
    manifests.pyproject.forEach(function (e) { txt.push(e.content); });
    manifests.goMod.forEach(function (e) { txt.push(e.content); });
    manifests.cargo.forEach(function (e) { txt.push(e.content); });
    manifests.gemfile.forEach(function (e) { txt.push(e.content); });
    manifests.composer.forEach(function (e) {
      const pkg = safeJson(e.content);
      if (pkg) txt.push(Object.keys(Object.assign({}, pkg.require || {}, pkg['require-dev'] || {})).join('\n'));
    });
    return { map: names, text: txt.join('\n').toLowerCase() };
  }

  function hasDep(depInfo, re) {
    return re.test(depInfo.text) || Object.keys(depInfo.map).some(function (n) { return re.test(n); });
  }

  function composeText(entries) {
    let t = '';
    entries.forEach(function (e) {
      const b = base(e.path);
      if (b === 'docker-compose.yml' || b === 'docker-compose.yaml' || b === 'compose.yml' || b === 'compose.yaml') {
        t += '\n' + (e.content || '');
      }
    });
    return t;
  }

  function detectServices(entries, manifests, envNames) {
    const out = [];
    const add = function (type, name, evidence, required) {
      pushUnique(out, function (s) { return s.type + '|' + s.name; }, { type: type, name: name, evidence: evidence, required: !!required });
    };
    const depInfo = gatherDepNames(manifests);
    const compose = composeText(entries).toLowerCase();
    const envBlob = (envNames || []).join(' ').toUpperCase();

    // Cache / Redis.
    if (hasDep(depInfo, /(^|[/])(redis|ioredis|redis-py|go-redis)/) || /REDIS_URL|REDIS_HOST/.test(envBlob) || /image:\s*redis|services:[\s\S]*\bredis\b/.test(compose)) {
      add('cache', 'Redis', 'redis client / REDIS_* / compose', true);
    }
    if (hasDep(depInfo, /memcached|pymemcache|memcache/) || /MEMCACHE/.test(envBlob)) {
      add('cache', 'Memcached', 'memcached client / MEMCACHE_*', false);
    }

    // Message brokers / queues.
    if (hasDep(depInfo, /amqplib|amqp|pika|kombu|bunny/) || /RABBITMQ|AMQP_URL/.test(envBlob) || /image:\s*rabbitmq/.test(compose)) {
      add('message-broker', 'RabbitMQ', 'amqp client / RABBITMQ / compose', true);
    }
    if (hasDep(depInfo, /kafkajs|confluent-kafka|sarama|kafka-python|rdkafka/) || /KAFKA/.test(envBlob) || /image:\s*(confluentinc\/)?cp-kafka|image:\s*.*kafka/.test(compose)) {
      add('message-broker', 'Kafka', 'kafka client / KAFKA_* / compose', true);
    }
    if (hasDep(depInfo, /\bnats\b/) || /NATS_URL/.test(envBlob)) {
      add('message-broker', 'NATS', 'nats client / NATS_*', false);
    }

    // Search.
    if (hasDep(depInfo, /elasticsearch|@elastic\/elasticsearch/) || /ELASTIC(SEARCH)?_/.test(envBlob) || /image:\s*.*elasticsearch/.test(compose)) {
      add('search', 'Elasticsearch', 'elasticsearch client / ELASTIC_* / compose', true);
    }
    if (hasDep(depInfo, /opensearch/) || /OPENSEARCH/.test(envBlob)) {
      add('search', 'OpenSearch', 'opensearch client / OPENSEARCH_*', true);
    }

    // SMTP / email.
    if (hasDep(depInfo, /nodemailer/) || /SMTP_(HOST|USER|PORT|PASS)|MAIL_HOST/.test(envBlob)) {
      add('smtp', 'SMTP', 'nodemailer / SMTP_* env', true);
    }

    // Object storage.
    if (hasDep(depInfo, /@aws-sdk\/client-s3|aws-sdk|boto3|minio/) || /S3_BUCKET|AWS_S3|BUCKET_NAME/.test(envBlob)) {
      add('object-storage', 'S3', 's3 sdk / S3_* env', false);
    }
    if (hasDep(depInfo, /@azure\/storage-blob|azure-storage-blob/) || /AZURE_BLOB|AZURE_STORAGE/.test(envBlob)) {
      add('object-storage', 'Azure Blob Storage', 'azure-blob sdk / AZURE_* env', false);
    }
    if (hasDep(depInfo, /@google-cloud\/storage|google-cloud-storage/) || /GCS_BUCKET/.test(envBlob)) {
      add('object-storage', 'Google Cloud Storage', 'gcs sdk / GCS_* env', false);
    }

    // Reverse proxy.
    entries.forEach(function (e) {
      const b = base(e.path);
      if (b === 'nginx.conf' || /nginx/.test(lc(e.path)) || /image:\s*nginx/.test((e.content || '').toLowerCase())) {
        add('reverse-proxy', 'nginx', 'nginx config / image', false);
      }
      if (/image:\s*traefik|traefik\.toml/.test((e.content || '').toLowerCase()) || base(e.path) === 'traefik.toml') {
        add('reverse-proxy', 'Traefik', 'traefik config / image', false);
      }
    });

    // Workers.
    if (hasDep(depInfo, /\bcelery\b/)) add('worker', 'Celery', 'celery dependency', true);
    if (hasDep(depInfo, /\bsidekiq\b/)) add('worker', 'Sidekiq', 'sidekiq dependency', true);
    if (hasDep(depInfo, /\bbull\b|bullmq/)) add('worker', 'Bull/BullMQ', 'bull dependency', true);
    if (hasDep(depInfo, /\brq\b/) && /redis/.test(depInfo.text)) add('worker', 'RQ', 'rq dependency', false);

    // Scheduler.
    if (hasDep(depInfo, /node-cron|node-schedule|agenda/) || /\bcrontab\b/.test(compose)) {
      add('scheduler', 'Cron/Scheduler', 'scheduler library', false);
    }
    if (hasDep(depInfo, /apscheduler/)) add('scheduler', 'APScheduler', 'apscheduler dependency', false);

    // Auth.
    if (hasDep(depInfo, /passport|next-auth|@auth\/|keycloak/)) {
      add('auth', 'Auth provider', 'auth library', false);
    }

    return out;
  }

  function detectDatabases(entries, manifests, envVars) {
    const out = [];
    const add = function (engine, evidence, migrationTool, connectionVar) {
      pushUnique(out, function (d) { return d.engine; }, {
        engine: engine, minVersion: null, evidence: evidence,
        migrationTool: migrationTool || null, connectionVar: connectionVar || null
      });
    };
    const depInfo = gatherDepNames(manifests);
    const compose = composeText(entries).toLowerCase();
    const envByName = {};
    (envVars || []).forEach(function (v) { envByName[v.name] = true; });
    const envNames = Object.keys(envByName);

    function findConnVar(re) {
      const m = envNames.filter(function (n) { return re.test(n); });
      return m.length ? m[0] : null;
    }

    // Connection-string schemes across all text.
    let schemes = '';
    entries.forEach(function (e) {
      const c = slice(e.content);
      const m = c.match(/\b(postgres(?:ql)?|mysql|mariadb|mongodb(?:\+srv)?|redis|sqlserver|mssql|oracle|sqlite):\/\//gi);
      if (m) schemes += ' ' + m.join(' ');
    });
    schemes = schemes.toLowerCase();

    // Migration tools.
    let mig = null;
    if (hasDep(depInfo, /\bprisma\b/)) mig = 'prisma';
    else if (hasDep(depInfo, /\bknex\b/)) mig = 'knex';
    else if (hasDep(depInfo, /\bsequelize\b/)) mig = 'sequelize';
    else if (hasDep(depInfo, /\balembic\b/)) mig = 'alembic';
    else if (hasDep(depInfo, /\bflyway\b/)) mig = 'flyway';
    else if (hasDep(depInfo, /\bliquibase\b/)) mig = 'liquibase';
    else if (hasDep(depInfo, /golang-migrate|\bmigrate\b/)) mig = 'migrate';
    else if (manifests.gemfile.length && /rails/.test(depInfo.text)) mig = 'rails';

    function efMig() { return hasDep(depInfo, /entityframework|ef-core|microsoft\.entityframeworkcore/) ? 'ef-core' : null; }

    if (hasDep(depInfo, /\bpg\b|node-postgres|psycopg2?|asyncpg|postgres|gorm.*postgres|pq\b/) || /postgres/.test(schemes) || /image:\s*postgres/.test(compose)) {
      add('PostgreSQL', 'pg driver / postgres:// / compose', mig || efMig(), findConnVar(/DATABASE_URL|POSTGRES|PG_/i) || 'DATABASE_URL');
    }
    if (hasDep(depInfo, /mysql2?|pymysql|mysqlclient/) || /mysql:\/\//.test(schemes) || /image:\s*mysql/.test(compose)) {
      add('MySQL', 'mysql driver / mysql:// / compose', mig || efMig(), findConnVar(/MYSQL|DATABASE_URL/i));
    }
    if (/mariadb:\/\//.test(schemes) || /image:\s*mariadb/.test(compose) || hasDep(depInfo, /mariadb/)) {
      add('MariaDB', 'mariadb:// / compose / driver', mig, findConnVar(/MARIADB|MYSQL|DATABASE_URL/i));
    }
    if (hasDep(depInfo, /mongodb|mongoose|pymongo|motor/) || /mongodb/.test(schemes) || /image:\s*mongo/.test(compose)) {
      add('MongoDB', 'mongo driver / mongodb:// / compose', null, findConnVar(/MONGO/i));
    }
    if (hasDep(depInfo, /mssql|tedious|pyodbc|microsoft\.data\.sqlclient/) || /(sqlserver|mssql):\/\//.test(schemes) || /image:\s*.*mssql/.test(compose)) {
      add('SQLServer', 'mssql driver / connection string / compose', efMig(), findConnVar(/SQLSERVER|MSSQL|DATABASE_URL/i));
    }
    if (hasDep(depInfo, /sqlite3?|better-sqlite3/) || /sqlite/.test(schemes)) {
      add('SQLite', 'sqlite driver / sqlite://', mig, null);
    }
    if (hasDep(depInfo, /\bcx_oracle\b|oracledb|node-oracledb/) || /oracle:\/\//.test(schemes)) {
      add('Oracle', 'oracle driver / oracle://', null, findConnVar(/ORACLE/i));
    }
    if (hasDep(depInfo, /@aws-sdk\/client-dynamodb|dynamodb|boto3/) && /DYNAMO|TABLE_NAME/.test(envNames.join(' ').toUpperCase())) {
      add('DynamoDB', 'dynamodb sdk + env', null, null);
    }
    if (hasDep(depInfo, /@azure\/cosmos|azure-cosmos/) || /COSMOS/.test(envNames.join(' ').toUpperCase())) {
      add('CosmosDB', 'cosmos sdk / COSMOS_*', null, findConnVar(/COSMOS/i));
    }
    if (hasDep(depInfo, /firebase-admin|@google-cloud\/firestore/) || /FIRESTORE|FIREBASE/.test(envNames.join(' ').toUpperCase())) {
      add('Firestore', 'firestore sdk / FIREBASE_*', null, null);
    }

    return out;
  }

  // env var name extraction
  function detectEnvVars(entries) {
    const collected = {}; // name -> { usedIn:Set, fromExampleOnly, hasDefault }

    function record(name, file, opts) {
      if (!name || !/^[A-Z][A-Z0-9_]{1,80}$/.test(name)) return;
      if (!collected[name]) collected[name] = { usedIn: {}, exampleOnly: true, hasDefault: false };
      const rec = collected[name];
      if (file) rec.usedIn[file] = true;
      if (opts && opts.fromExample) {
        if (opts.hasDefault) rec.hasDefault = true;
      } else {
        rec.exampleOnly = false;
      }
    }

    entries.forEach(function (e) {
      const c = slice(e.content);
      if (!c) return;
      const path = e.path;
      const b = base(path);
      const isExample = /\.env(\.example|\.sample|\.template)?$/.test(b) || b === '.env.example';

      try {
        // process.env.NAME / process.env['NAME']
        let m;
        const re1 = /process\.env(?:\.([A-Z][A-Z0-9_]*)|\[\s*['"]([A-Z][A-Z0-9_]*)['"]\s*\])/g;
        while ((m = re1.exec(c))) record(m[1] || m[2], path);

        // os.environ['NAME'] / os.getenv('NAME') / os.environ.get('NAME')
        const re2 = /os\.(?:environ(?:\.get)?\[?\(?|getenv\()\s*['"]([A-Z][A-Z0-9_]*)['"]/g;
        while ((m = re2.exec(c))) record(m[1], path);
        const re2b = /os\.environ\[\s*['"]([A-Z][A-Z0-9_]*)['"]\s*\]/g;
        while ((m = re2b.exec(c))) record(m[1], path);

        // System.getenv("NAME") / getenv("NAME")
        const re3 = /(?:System\.)?getenv\(\s*['"]([A-Z][A-Z0-9_]*)['"]/g;
        while ((m = re3.exec(c))) record(m[1], path);

        // Rails ENV['NAME'] / ENV.fetch('NAME')
        const re4 = /ENV(?:\.fetch)?\[?\(?\s*['"]([A-Z][A-Z0-9_]*)['"]/g;
        while ((m = re4.exec(c))) record(m[1], path);

        // Go os.Getenv
        const re5 = /os\.Getenv\(\s*['"]([A-Z][A-Z0-9_]*)['"]/g;
        while ((m = re5.exec(c))) record(m[1], path);

        // CI secrets: ${{ secrets.NAME }}
        const re6 = /\$\{\{\s*secrets\.([A-Z][A-Z0-9_]*)\s*\}\}/g;
        while ((m = re6.exec(c))) record(m[1], path);

        // Dockerfile / compose / shell / yaml: $NAME and ${NAME}
        if (/dockerfile/.test(b) || /\.(ya?ml|sh|bash|env)$/.test(b) || /compose/.test(b) || isExample || /procfile/.test(b)) {
          const re7 = /\$\{?([A-Z][A-Z0-9_]{2,})\}?/g;
          while ((m = re7.exec(c))) record(m[1], path);
          // Dockerfile ARG/ENV declarations
          const re8 = /^\s*(?:ARG|ENV)\s+([A-Z][A-Z0-9_]*)\b/gim;
          while ((m = re8.exec(c))) record(m[1], path);
        }

        // .env / .env.example keys (left of '=')
        if (isExample || b === '.env') {
          c.split('\n').forEach(function (line) {
            const lm = line.match(/^\s*(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=(.*)$/);
            if (lm) {
              const val = (lm[2] || '').trim();
              record(lm[1], path, { fromExample: isExample, hasDefault: val.length > 0 });
            }
          });
        }
      } catch (err) { /* skip malformed */ }
    });

    const names = Object.keys(collected).sort();
    return names.map(function (name) {
      const rec = collected[name];
      return {
        name: name,
        category: categorizeEnv(name),
        usedIn: Object.keys(rec.usedIn).slice(0, 5),
        required: !(rec.exampleOnly && rec.hasDefault),
        secret: /(SECRET|_KEY$|^KEY$|API_?KEY|TOKEN|PASSWORD|PASSWD|PRIVATE|CREDENTIAL)/.test(name)
      };
    });
  }

  function categorizeEnv(name) {
    if (/DATABASE|(^|_)DB(_|$)|POSTGRES|^PG|MYSQL|MONGO|SQL/.test(name)) return 'database';
    if (/JWT|SECRET|TOKEN|SESSION|OAUTH|AUTH|PASSWORD|CREDENTIAL/.test(name)) return 'auth';
    if (/AWS|AZURE|GCP|GOOGLE_CLOUD|GCLOUD/.test(name)) return 'cloud';
    if (/OPENAI|ANTHROPIC|COHERE|HUGGING|(^|_)HF(_|$)/.test(name)) return 'ai';
    if (/STRIPE|PADDLE|PAYPAL|BRAINTREE/.test(name)) return 'payment';
    if (/SMTP|MAIL|SENDGRID|MAILGUN|POSTMARK|SES_/.test(name)) return 'email';
    if (/SLACK|DISCORD|TWILIO|TELEGRAM/.test(name)) return 'messaging';
    if (/ELASTIC|OPENSEARCH|ALGOLIA|MEILI/.test(name)) return 'search';
    if (/REDIS|MEMCACHE/.test(name)) return 'cache';
    if (/(^|_)S3(_|$)|BLOB|GCS|BUCKET|MINIO/.test(name)) return 'storage';
    if (/SENTRY|DATADOG|OTEL|NEWRELIC|HONEYCOMB/.test(name)) return 'telemetry';
    if (/PORT|HOST|NODE_ENV|APP_|BASE_URL|LOG_LEVEL/.test(name)) return 'app';
    return 'other';
  }

  function detectPorts(entries) {
    const out = [];
    const add = function (port, direction, protocol, evidence) {
      const p = parseInt(port, 10);
      if (!p || p < 1 || p > 65535) return;
      pushUnique(out, function (x) { return x.port + '|' + x.direction; }, {
        port: p, direction: direction, protocol: protocol, evidence: evidence
      });
    };

    entries.forEach(function (e) {
      const c = slice(e.content);
      if (!c) return;
      const b = base(e.path);
      try {
        // EXPOSE n (Dockerfile)
        if (/dockerfile/.test(b)) {
          const re = /^\s*EXPOSE\s+([\d\s/a-z]+)/gim;
          let m;
          while ((m = re.exec(c))) {
            (m[1].match(/\d+/g) || []).forEach(function (n) { add(n, 'listen', /443/.test(n) ? 'https' : 'tcp', 'Dockerfile EXPOSE'); });
          }
        }
        // compose ports: "x:y" or - "x:y"
        if (/compose/.test(b) || b === 'compose.yml' || b === 'compose.yaml') {
          const re = /["']?(\d{2,5}):(\d{2,5})["']?/g;
          let m;
          while ((m = re.exec(c))) add(m[2], 'listen', 'tcp', 'compose ports mapping');
        }
        // app.listen(3000) / .listen(PORT, ...) numeric
        const lre = /\.listen\(\s*(\d{2,5})/g;
        let lm;
        while ((lm = lre.exec(c))) add(lm[1], 'listen', 'http', 'app.listen()');
        // server on :8080
        const sre = /(?:addr|listen|bind)\s*[:=]?\s*['"]?:(\d{2,5})/gi;
        let sm;
        while ((sm = sre.exec(c))) add(sm[1], 'listen', 'tcp', 'listen address');
        // PORT= in env/Procfile
        if (/\.env|procfile|compose|dockerfile/.test(b)) {
          const pm = c.match(/^\s*(?:ENV\s+)?PORT\s*[=:]\s*(\d{2,5})/im);
          if (pm) add(pm[1], 'listen', 'tcp', 'PORT env');
        }
      } catch (err) { /* skip */ }
    });

    return out;
  }

  // ---------------------------------------------------------------------------
  // BUILD
  // ---------------------------------------------------------------------------

  function detectBuild(entries, manifests, packageManagers) {
    const build = {
      install: null, build: null, compile: null, migrate: null, seed: null,
      lint: null, test: null, run: null, start: null, docker: null
    };

    // package.json scripts.
    manifests.pkgJson.forEach(function (e) {
      const pkg = safeJson(e.content);
      if (!pkg) return;
      const scripts = pkg.scripts || {};
      const pm = (packageManagers.find(function (p) { return /npm|yarn|pnpm|bun/.test(p.name); }) || {}).name || 'npm';
      const runner = pm === 'npm' ? 'npm run' : (pm === 'yarn' ? 'yarn' : (pm === 'pnpm' ? 'pnpm' : 'bun run'));
      if (!build.install) build.install = pm === 'npm' ? 'npm install' : (pm + ' install');
      if (scripts.build && !build.build) build.build = runner + ' build';
      if (scripts.test && !build.test) build.test = runner + ' test';
      if (scripts.lint && !build.lint) build.lint = runner + ' lint';
      if ((scripts.migrate || scripts['db:migrate']) && !build.migrate) build.migrate = runner + ' ' + (scripts.migrate ? 'migrate' : 'db:migrate');
      if ((scripts.seed || scripts['db:seed']) && !build.seed) build.seed = runner + ' ' + (scripts.seed ? 'seed' : 'db:seed');
      if (scripts.start && !build.start) build.start = pm === 'npm' ? 'npm start' : (runner + ' start');
      if (scripts.dev && !build.run) build.run = runner + ' dev';
      if (!build.start && !build.run && scripts.serve) build.run = runner + ' serve';
    });

    // Python.
    if (manifests.requirements.length && !build.install) build.install = 'pip install -r requirements.txt';
    manifests.pyproject.forEach(function (e) {
      const c = e.content || '';
      if (/\[tool\.poetry\]/.test(c)) {
        if (!build.install) build.install = 'poetry install';
        if (!build.run) build.run = 'poetry run';
      }
    });

    // Go.
    if (manifests.goMod.length) {
      if (!build.install) build.install = 'go mod download';
      if (!build.build) build.build = 'go build ./...';
      if (!build.test) build.test = 'go test ./...';
      if (!build.run) build.run = 'go run .';
    }

    // Rust.
    if (manifests.cargo.length) {
      if (!build.build) build.build = 'cargo build --release';
      if (!build.test) build.test = 'cargo test';
      if (!build.run) build.run = 'cargo run';
    }

    // Maven / Gradle.
    if (manifests.pom.length) {
      if (!build.install) build.install = 'mvn install';
      if (!build.build) build.build = 'mvn package';
      if (!build.test) build.test = 'mvn test';
    }
    if (manifests.gradle.length) {
      if (!build.build) build.build = './gradlew build';
      if (!build.test) build.test = './gradlew test';
    }

    // .NET.
    if (manifests.csproj.length) {
      if (!build.install) build.install = 'dotnet restore';
      if (!build.build) build.build = 'dotnet build';
      if (!build.test) build.test = 'dotnet test';
      if (!build.run) build.run = 'dotnet run';
    }

    // PHP composer scripts.
    manifests.composer.forEach(function (e) {
      const pkg = safeJson(e.content);
      if (!pkg) return;
      if (!build.install) build.install = 'composer install';
      const s = pkg.scripts || {};
      if (s.test && !build.test) build.test = 'composer test';
    });

    // Ruby.
    if (manifests.gemfile.length && !build.install) build.install = 'bundle install';

    entries.forEach(function (e) {
      const b = base(e.path);
      const c = slice(e.content);
      // Makefile targets.
      if (b === 'makefile' || b === 'gnumakefile') {
        if (/^build:/m.test(c) && !build.build) build.build = 'make build';
        if (/^test:/m.test(c) && !build.test) build.test = 'make test';
        if (/^install:/m.test(c) && !build.install) build.install = 'make install';
        if (/^lint:/m.test(c) && !build.lint) build.lint = 'make lint';
        if (/^run:/m.test(c) && !build.run) build.run = 'make run';
        if (/^migrate:/m.test(c) && !build.migrate) build.migrate = 'make migrate';
      }
      // Dockerfile CMD/ENTRYPOINT.
      if (b === 'dockerfile' || b.indexOf('dockerfile.') === 0) {
        const cmd = c.match(/^\s*(?:CMD|ENTRYPOINT)\s+(.+)$/im);
        if (cmd && !build.docker) {
          let v = cmd[1].trim();
          try {
            if (/^\[/.test(v)) {
              const arr = JSON.parse(v);
              v = Array.isArray(arr) ? arr.join(' ') : v;
            }
          } catch (err) { /* keep raw */ }
          build.docker = v;
        }
      }
      // Procfile web:
      if (b === 'procfile') {
        const web = c.match(/^web:\s*(.+)$/m);
        if (web && !build.start) build.start = web[1].trim();
      }
      // render.yaml startCommand
      if (b === 'render.yaml') {
        const sc = c.match(/startCommand:\s*(.+)/);
        if (sc && !build.start) build.start = sc[1].trim().replace(/^["']|["']$/g, '');
        const bc = c.match(/buildCommand:\s*(.+)/);
        if (bc && !build.build) build.build = bc[1].trim().replace(/^["']|["']$/g, '');
      }
    });

    return build;
  }

  // ---------------------------------------------------------------------------
  // EXTERNAL SERVICES
  // ---------------------------------------------------------------------------

  function detectExternalServices(entries, manifests, envVars) {
    const out = [];
    const add = function (name, category, evidence) {
      pushUnique(out, function (s) { return s.name; }, { name: name, category: category, evidence: evidence });
    };
    const depInfo = gatherDepNames(manifests);
    const envBlob = (envVars || []).map(function (v) { return v.name; }).join(' ').toUpperCase();
    let srcBlob = '';
    entries.forEach(function (e) {
      const c = slice(e.content);
      if (c && c.length < MAX_SCAN) srcBlob += '\n' + c;
    });
    const blob = (depInfo.text + '\n' + srcBlob).toLowerCase();

    function any(re, env) {
      return re.test(blob) || (env && env.test(envBlob));
    }

    if (any(/@microsoft\/microsoft-graph-client|microsoft-graph|graph\.microsoft\.com/, /GRAPH_/)) add('Microsoft Graph', 'cloud', 'graph client / graph.microsoft.com');
    if (any(/@azure\/|azure-identity|\.azure\.com|azurerm/, /AZURE_/)) add('Azure', 'cloud', 'azure sdk / env');
    if (any(/aws-sdk|@aws-sdk\/|boto3|amazonaws\.com/, /AWS_/)) add('AWS', 'cloud', 'aws sdk / env');
    if (any(/@google-cloud\/|googleapis|google-cloud-/, /GCP_|GOOGLE_/)) add('Google Cloud', 'cloud', 'gcp sdk / env');
    if (any(/\bopenai\b/, /OPENAI_/)) add('OpenAI', 'ai', 'openai sdk / OPENAI_*');
    if (any(/@anthropic-ai\/|anthropic/, /ANTHROPIC_/)) add('Anthropic', 'ai', 'anthropic sdk / ANTHROPIC_*');
    if (any(/\bstripe\b/, /STRIPE_/)) add('Stripe', 'payment', 'stripe sdk / STRIPE_*');
    if (any(/\btwilio\b/, /TWILIO_/)) add('Twilio', 'messaging', 'twilio sdk / TWILIO_*');
    if (any(/@sendgrid\/|sendgrid/, /SENDGRID_/)) add('SendGrid', 'email', 'sendgrid sdk / SENDGRID_*');
    if (any(/@slack\/|slack_sdk|slack\.com\/api|hooks\.slack/, /SLACK_/)) add('Slack', 'messaging', 'slack sdk / SLACK_*');
    if (any(/discord\.js|discordpy|discord\.com\/api/, /DISCORD_/)) add('Discord', 'messaging', 'discord sdk / DISCORD_*');
    if (any(/api\.github\.com|@octokit\/|octokit/, /GITHUB_TOKEN/)) add('GitHub', 'devtools', 'github api / octokit');
    if (any(/gitlab\.com\/api|@gitbeaker/, /GITLAB_/)) add('GitLab', 'devtools', 'gitlab api');
    if (any(/atlassian\.net\/rest|jira/, /JIRA_/)) add('Jira', 'devtools', 'jira api / JIRA_*');
    if (any(/\/wiki\/rest\/api|confluence/, null)) add('Confluence', 'devtools', 'confluence api');
    if (any(/salesforce|force\.com|jsforce/, /SALESFORCE_|SF_/)) add('Salesforce', 'crm', 'salesforce sdk / api');
    if (any(/service-now|servicenow|\.service-now\.com/, /SERVICENOW_/)) add('ServiceNow', 'crm', 'servicenow api');
    if (any(/\bokta\b|okta\.com/, /OKTA_/)) add('Okta', 'identity', 'okta sdk / OKTA_*');
    if (any(/auth0|@auth0\//, /AUTH0_/)) add('Auth0', 'identity', 'auth0 sdk / AUTH0_*');
    if (any(/ldapjs|\bldap:\/\/|python-ldap/, /LDAP_/)) add('LDAP', 'identity', 'ldap client / LDAP_*');
    if (any(/active-directory|activedirectory|\bldaps?:\/\//, /AD_|ACTIVE_DIRECTORY/)) add('Active Directory', 'identity', 'AD references');
    if (/\bsoap\b|wsdl|xmlns:soap/.test(blob)) add('SOAP API', 'protocol', 'SOAP/WSDL references');
    if (/\bwebsocket\b|\bws:\/\/|\bwss:\/\/|socket\.io/.test(blob)) add('WebSocket', 'protocol', 'websocket references');
    if (/axios|node-fetch|\bfetch\(|requests\.(get|post)|httpclient|resttemplate/.test(blob)) add('REST API', 'protocol', 'http client usage');

    return out;
  }

  // ---------------------------------------------------------------------------
  // INFRA (superset of detectDeployment)
  // ---------------------------------------------------------------------------

  function detectInfra(entries) {
    const out = [];
    const add = function (type, file, detail) {
      pushUnique(out, function (s) { return s.type + '|' + s.file; }, { type: type, file: file, detail: detail });
    };

    entries.forEach(function (e) {
      const p = lc(e.path);
      const b = base(e.path);
      const c = e.content || '';
      const cl = c.length > MAX_SCAN ? c.slice(0, MAX_SCAN) : c;

      try {
        if (b === 'dockerfile' || b.indexOf('dockerfile.') === 0) add('docker', e.path, 'Containerized build');
        if (b === 'docker-compose.yml' || b === 'docker-compose.yaml' || b === 'compose.yml' || b === 'compose.yaml') add('docker-compose', e.path, 'Multi-container orchestration');
        if (/kind:\s*(Deployment|Service|Pod|StatefulSet|DaemonSet|Ingress|ConfigMap|ReplicaSet|Job|CronJob|Namespace)/.test(cl)) add('kubernetes', e.path, 'K8s manifest');
        if (b === 'chart.yaml' || /(^|\/)templates\/.*\.ya?ml$/.test(p)) add('helm', e.path, 'Kubernetes packaging (Helm)');
        if (b === 'kustomization.yaml' || b === 'kustomization.yml') add('kustomize', e.path, 'Kustomize overlay');
        if (/\.tf$/.test(p) || b === 'main.tf' || /\.tfvars$/.test(p)) add('terraform', e.path, 'Infrastructure as Code');
        if (/^playbook.*\.ya?ml$/.test(b) || /(^|\/)(roles|playbooks)\//.test(p) || b === 'ansible.cfg') add('ansible', e.path, 'Ansible automation');
        if (b === 'pulumi.yaml' || b === 'pulumi.yml') add('pulumi', e.path, 'Pulumi IaC');
        if (/\.bicep$/.test(p)) add('bicep', e.path, 'Azure Bicep IaC');
        if (b === 'azuredeploy.json' || (/\.json$/.test(p) && /"\$schema"[^\n]*deploymentTemplate/.test(cl))) add('arm', e.path, 'Azure ARM template');
        if ((/\.ya?ml$/.test(p) && /AWSTemplateFormatVersion/.test(cl)) || b === 'cloudformation.yaml' || b === 'cloudformation.yml' || (/AWS::Serverless/.test(cl))) add('cloudformation', e.path, 'AWS CloudFormation/SAM');
        if (b === 'nginx.conf' || (/\.conf$/.test(p) && /server\s*\{[\s\S]*listen\s+\d/.test(cl)) || /nginx/.test(p)) add('nginx', e.path, 'nginx config');
        if (b === '.htaccess' || b === 'httpd.conf' || b === 'apache2.conf') add('apache', e.path, 'Apache config');
        if (b === 'web.config') add('iis', e.path, 'IIS config');
        if (/\.service$/.test(p) && /\[Unit\]|\[Service\]/.test(cl)) add('systemd', e.path, 'systemd unit');
        if (b === 'crontab' || /\.cron$/.test(p) || /(^|\/)cron\.d\//.test(p)) add('cron', e.path, 'cron schedule');
        if (/\.(pem|crt|cer|key)$/.test(p) || /BEGIN CERTIFICATE/.test(cl)) add('ssl', e.path, 'TLS certificate/key');
        if (/dns/.test(b) && /\.(zone|tf|ya?ml)$/.test(p) || /(A|CNAME|MX|TXT)\s+record/i.test(cl) && /dns/.test(p)) add('dns', e.path, 'DNS configuration');
        if (/kind:\s*PersistentVolume(Claim)?/.test(cl)) add('persistent-volumes', e.path, 'Persistent volume');
        if (/hashicorp\/vault|vault\.|kind:\s*(Secret|ExternalSecret)|secretsmanager|key.?vault/i.test(cl)) add('secrets-manager', e.path, 'Secrets management');
        if (/kind:\s*(Ingress|Service)[\s\S]*type:\s*LoadBalancer|aws_lb\b|azurerm_lb\b|load.?balancer/i.test(cl)) add('load-balancer', e.path, 'Load balancer');
      } catch (err) { /* skip */ }
    });

    return out;
  }

  // ---------------------------------------------------------------------------
  // ORCHESTRATION
  // ---------------------------------------------------------------------------

  function analyze(entries) {
    const text = textEntries(entries);
    const manifests = collectManifests(text);

    let languages = [], frameworks = [], runtimes = [], sdks = [], compilers = [];
    let packageManagers = [], os = [], arch = [], shells = [], cloud = [];
    let services = [], databases = [], envVars = [], ports = [];
    let build = null, externalServices = [], infra = [];

    try { languages = detectLanguages(text); } catch (e) { languages = []; }
    try { frameworks = detectFrameworks(text, manifests); } catch (e) { frameworks = []; }
    try { runtimes = detectRuntimes(text, manifests); } catch (e) { runtimes = []; }
    try {
      const sc = detectSdksCompilers(text, manifests);
      sdks = sc.sdks; compilers = sc.compilers;
    } catch (e) { sdks = []; compilers = []; }
    try { packageManagers = detectPackageManagers(text); } catch (e) { packageManagers = []; }
    try {
      const oas = detectOsArchShells(text);
      os = oas.os; arch = oas.arch; shells = oas.shells;
    } catch (e) { os = []; arch = []; shells = []; }
    try { cloud = detectCloud(text, manifests); } catch (e) { cloud = []; }
    try { envVars = detectEnvVars(text); } catch (e) { envVars = []; }
    try { databases = detectDatabases(text, manifests, envVars); } catch (e) { databases = []; }
    try {
      const envNames = envVars.map(function (v) { return v.name; });
      services = detectServices(text, manifests, envNames);
    } catch (e) { services = []; }
    try { ports = detectPorts(text); } catch (e) { ports = []; }
    try { build = detectBuild(text, manifests, packageManagers); } catch (e) {
      build = { install: null, build: null, compile: null, migrate: null, seed: null, lint: null, test: null, run: null, start: null, docker: null };
    }
    try { externalServices = detectExternalServices(text, manifests, envVars); } catch (e) { externalServices = []; }
    try { infra = detectInfra(text); } catch (e) { infra = []; }

    const stack = {
      languages: languages,
      frameworks: frameworks,
      runtimes: runtimes,
      sdks: sdks,
      compilers: compilers,
      packageManagers: packageManagers,
      os: os,
      arch: arch,
      shells: shells,
      databases: uniqBy(databases.map(function (d) { return d.engine; }).map(function (n) { return { n: n }; }), function (x) { return x.n; }).map(function (x) { return x.n; }),
      infra: uniqBy(infra.map(function (i) { return { n: i.type }; }), function (x) { return x.n; }).map(function (x) { return x.n; }),
      cloud: cloud
    };

    return {
      stack: stack,
      runtime: {
        services: services,
        databases: databases,
        envVars: envVars,
        ports: ports
      },
      build: build,
      externalServices: externalServices,
      infra: infra
    };
  }

  CITADEL.depreviewRuntime = { analyze: analyze };
})(window);
