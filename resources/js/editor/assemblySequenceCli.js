import { readFileSync } from 'node:fs';
import { checkAssemblySequence } from './assemblySequence.js';

const { steps, instances, fasteners } = JSON.parse(readFileSync(0, 'utf8'));
process.stdout.write(JSON.stringify(checkAssemblySequence(steps, instances, fasteners)));
