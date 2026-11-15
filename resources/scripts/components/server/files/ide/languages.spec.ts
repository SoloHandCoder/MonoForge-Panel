import { describe, expect, it } from 'vitest';
import { detectLanguage } from './languages';

describe('detectLanguage', () => {
    it.each([
        ['config.yml', 'yaml', 'YAML'],
        ['docker-compose.YAML', 'yaml', 'YAML'],
        ['ops.json', 'json', 'JSON'],
        ['server.properties', 'ini', 'Properties'],
        ['pyproject.toml', 'ini', 'TOML'],
        ['index.js', 'javascript', 'JavaScript'],
        ['main.lua', 'lua', 'Lua'],
        ['hello.sk', 'skript', 'Skript'],
        ['spigot.cfg', 'ini', 'Config'],
        ['php.ini', 'ini', 'INI'],
        ['pom.xml', 'xml', 'XML'],
        ['README.md', 'markdown', 'Markdown'],
        ['start.sh', 'shell', 'Shell'],
        ['Dockerfile', 'dockerfile', 'Dockerfile'],
        ['/srv/Dockerfile.dev', 'dockerfile', 'Dockerfile'],
        ['.env', 'ini', 'Environment'],
        ['latest.log', 'plaintext', 'Plain Text'],
        ['Makefile', 'plaintext', 'Plain Text'],
        ['.gitignore', 'plaintext', 'Plain Text'],
    ])('reads %s as %s', (name, id, label) => {
        expect(detectLanguage(name)).toEqual({ id, label });
    });
});
