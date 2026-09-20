import test from 'node:test';
import assert from 'node:assert/strict';
import {createTerminalProtocol, terminalText} from '../../resource/src/js/modules/app/terminal-protocol.mjs';

function fixture() {
    const sent = [], shown = [];
    const socket = {readyState: 1, send: value => sent.push(JSON.parse(value))};
    const client = createTerminalProtocol(socket, value => shown.push(value));
    return {sent, shown, socket, client};
}

test('commands require authorization readiness and send exactly once', () => {
    const {client, sent} = fixture();
    assert.equal(client.submit('list notes'), false);
    client.receive('{"type":"ready"}');
    assert.equal(client.submit('list notes'), true);
    assert.equal(client.submit('list notes'), false);
    assert.deepEqual(sent, [{type:'command', input:'list notes'}]);
    client.receive(JSON.stringify({type:'result', accepted:true, result:{status:'completed', output_text:'ready'}}));
    assert.equal(client.submit('next note'), true);
});

test('confirmation is explicit, single-use, and never queued offline', () => {
    const {client, sent} = fixture();
    client.receive('{"type":"ready"}');
    client.receive(JSON.stringify({type:'result', accepted:true, result:{status:'confirmation_required', confirmation_required:true, continuation_token:'token'}}));
    assert.equal(client.submit('delete everything'), false);
    assert.equal(client.submit('no'), true);
    assert.deepEqual(sent, [{type:'confirm', token:'token', approved:false}]);
    assert.equal(client.submit('no'), false);
    client.close();
    assert.equal(client.submit('retry'), false);
    assert.equal(sent.length, 1);
});

test('malformed responses, transport errors and oversized input do not dispatch', () => {
    const {client, sent, socket} = fixture();
    client.receive('{"type":"ready"}');
    assert.equal(client.submit('界'.repeat(6000)), false);
    client.receive('not-json');
    assert.equal(client.submit('list notes'), false);
    const next = fixture();
    next.client.receive('{"type":"ready"}');
    next.socket.send = () => { throw new Error('private'); };
    assert.equal(next.client.submit('list notes'), false);
    assert.equal(next.client.submit('retry'), false);
    assert.equal(sent.length, 0);
});

test('untrusted output cannot emit terminal escape or control sequences', () => {
    assert.equal(terminalText('a\x1b]52;c;secret\x07\r\nb'), 'a]52;c;secret\nb');
});

test('transport rejections preserve pending approval while executed denials consume it', () => {
    const {client, sent} = fixture();
    client.receive('{"type":"ready"}');
    client.receive(JSON.stringify({type:'result', accepted:true, result:{confirmation_required:true, continuation_token:'token'}}));
    client.submit('yes');
    client.receive(JSON.stringify({type:'result', accepted:false, result:{status:'invalid'}}));
    assert.equal(client.submit('no'), true);
    assert.deepEqual(sent[1], {type:'confirm', token:'token', approved:false});
    client.receive(JSON.stringify({type:'result', accepted:true, result:{status:'invalid'}}));
    assert.equal(client.submit('list notes'), true);
    assert.deepEqual(sent[2], {type:'command', input:'list notes'});
});

test('missing continuation and closed transports cannot be revived by late frames', () => {
    const {client, socket, sent} = fixture();
    let closes = 0;
    socket.close = () => { closes++; };
    client.receive('{"type":"ready"}');
    client.receive(JSON.stringify({type:'result', accepted:true, result:{confirmation_required:true}}));
    client.receive('{"type":"ready"}');
    assert.equal(client.submit('yes'), false);
    client.close();
    assert.equal(closes, 1);
    assert.equal(sent.length, 0);
});
