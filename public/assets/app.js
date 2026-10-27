'use strict';
const $ = id => document.getElementById(id);
const number = value => Number(value).toLocaleString();
const age = days => (Number(days) / 365).toFixed(1);
const state = {world:null, snapshot:null, selected:null, tile:[0,0], epoch:0, personEpoch:0, eventEpoch:0, cursor:null};
const colors = {grass:'#a6bf78',forest:'#486c45',water:'#7ba8b0',mountain:'#a8a38d'};
function element(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}
function notice(text, error=false) { $('notice').textContent=text; $('notice').classList.toggle('error',error); }
async function api(path) {
  const response = await fetch(path,{headers:{Accept:'application/json'},cache:'no-store'});
  const data = await response.json();
  if (!response.ok) throw new Error(data.error || 'Unable to read world data');
  return data;
}
function personButton(id, name) {
  const button=element('button','link-button',name || `Person #${id}`);
  button.type='button'; button.addEventListener('click',()=>loadPerson(id)); return button;
}
function renderPeople() {
  const people=state.snapshot?.people || [];
  const search=$('search').value.trim().toLowerCase();
  const matches=people.filter(person=>person.name.toLowerCase().includes(search) || String(person.id)===search);
  $('people-list').replaceChildren();
  $('people-count').textContent=`${number(people.length)} shown`;
  for (const person of matches) {
    const row=element('button','person-row'); row.type='button';
    row.classList.toggle('selected',String(person.id)===String(state.selected));
    const text=element('span');text.append(element('strong','',person.name),element('small','',`#${person.id} · ${person.sex || 'Unknown sex'} · ${Number(person.age_ticks)<6570?'Child':'Adult'}`));
    row.append(text,element('span','age',`${age(person.age_ticks)} yr`));row.addEventListener('click',()=>loadPerson(person.id));
    $('people-list').append(row);
  }
  if (!matches.length) $('people-list').append(element('p','empty',people.length?'No matching people.':'No living people in this world.'));
}
function drawMap() {
  if (!state.snapshot) return;
  const canvas=$('map'),ctx=canvas.getContext('2d'),tiles=state.snapshot.tiles;
  const columns=Math.max(1,...tiles.map(tile=>Number(tile.x)+1)),rows=Math.max(1,...tiles.map(tile=>Number(tile.y)+1));
  state.columns=columns;state.rows=rows;
  const w=canvas.width/columns,h=canvas.height/rows;
  ctx.fillStyle='#e2e5d6';ctx.fillRect(0,0,canvas.width,canvas.height);
  for (const tile of tiles) {
    ctx.fillStyle=colors[tile.terrain] || '#ccc';ctx.fillRect(Number(tile.x)*w,Number(tile.y)*h,w+.2,h+.2);
    if (tile.terrain==='forest' && w>8) {ctx.fillStyle='#3c5d3a';ctx.fillRect(Number(tile.x)*w+w*.35,Number(tile.y)*h+h*.3,w*.3,h*.4);}
  }
  const occupied=new Map();
  for (const person of state.snapshot.people) {const key=`${person.x},${person.y}`;occupied.set(key,(occupied.get(key)||0)+1);}
  for (const [key,count] of occupied) {
    const [x,y]=key.split(',').map(Number);ctx.beginPath();ctx.arc((x+.5)*w,(y+.5)*h,Math.max(2,Math.min(w,h)*.23+Math.min(count-1,3)),0,Math.PI*2);
    ctx.fillStyle='#fff4cc';ctx.fill();ctx.strokeStyle='#433b24';ctx.lineWidth=1.5;ctx.stroke();
  }
  const selected=state.snapshot.people.find(person=>String(person.id)===String(state.selected));
  const [x,y]=selected ? [Number(selected.x),Number(selected.y)] : state.tile;
  ctx.strokeStyle='#fff';ctx.lineWidth=2;ctx.strokeRect(x*w+1,y*h+1,Math.max(1,w-2),Math.max(1,h-2));
  $('map-size').textContent=`${columns} × ${rows} tiles`;
}
function inspectTile(x,y) {
  state.tile=[x,y]; state.selected=null; state.personEpoch++;
  $('person-title').textContent='Person details';$('person-details').replaceChildren(element('p','muted','Choose a person on this tile or in the list.'));
  const tile=state.snapshot?.tiles.find(tile=>Number(tile.x)===x && Number(tile.y)===y);
  const people=state.snapshot?.people.filter(person=>Number(person.x)===x && Number(person.y)===y) || [];
  const details=$('tile-details');details.replaceChildren();
  details.append(element('div','',tile?`Tile (${x}, ${y}) · ${tile.terrain} · Food ${number(tile.food)} / ${number(tile.food_capacity)}`:`Tile (${x}, ${y}) · No terrain data`));
  for (const person of people) details.append(personButton(person.id,person.name));
  if (!people.length) details.append(element('div','small-note','No living people on this tile.'));
  renderPeople();drawMap();
}
async function loadPerson(id) {
  if (!state.world) return;
  const epoch=state.epoch,request=++state.personEpoch;
  state.selected=id;renderPeople();drawMap();
  $('person-title').textContent='Person details';$('person-details').replaceChildren(element('p','muted','Loading person…'));
  try {
    const data=await api(`/api/worlds/${state.world}/people/${encodeURIComponent(id)}`);
    if (epoch!==state.epoch || request!==state.personEpoch) return;
    const person=data.person,alive=Number(person.alive)===1;
    $('person-title').textContent=person.name;
    const content=$('person-details');content.replaceChildren();
    content.append(element('p','detail-status',`#${person.id} · ${person.sex || 'Unknown sex'} · ${alive?'Living':'Deceased'} · Tile (${person.x}, ${person.y})`));
    const grid=element('div','detail-grid');
    for (const [label,value] of [[alive?'Age':'Age at death',`${age(person.age_ticks)} yr`],['Health',`${person.health}/100`],['Hunger',`${person.hunger}/100`],['Energy',`${person.energy}/100`],['Food',number(person.food_inventory)],['Life stage',Number(person.age_ticks)<6570?'Child':'Adult']]) {
      const cell=element('div');cell.append(element('span','',label),element('strong','',value));grid.append(cell);
    }
    content.append(grid);
    if (data.death) content.append(element('p','',`${data.death.details} · Tick ${number(data.death.tick)}`));
    content.append(element('p','family-heading','PARENTS'));
    for (const role of ['mother','father']) {
      if (person[`${role}_id`]) content.append(personButton(person[`${role}_id`],`${role==='mother'?'Mother':'Father'}: ${person[`${role}_name`]}`));
      else content.append(element('p','small-note',`${role==='mother'?'Mother':'Father'}: not recorded`));
    }
    content.append(element('p','family-heading','CHILDREN'));
    for (const child of data.children) content.append(personButton(child.id,`${child.name} · ${age(child.age_ticks)} yr${Number(child.alive)?'':' · deceased'}`));
    if (!data.children.length) content.append(element('p','small-note','No children recorded.'));
    if (data.children_truncated) content.append(element('p','small-note','Showing the first 200 children.'));
  } catch(error) {
    if (epoch===state.epoch && request===state.personEpoch) $('person-details').replaceChildren(element('p','muted',error.message));
  }
}
async function loadEvents(append=false) {
  if (!state.world) return;
  const epoch=state.epoch,request=++state.eventEpoch,button=$('more-events');button.disabled=true;
  if (!append) {$('events').replaceChildren(element('p','empty','Loading history…'));state.cursor=null;button.hidden=true;}
  const query=new URLSearchParams({kind:$('event-filter').value,limit:'30'});
  if (append && state.cursor) query.set('before',state.cursor);
  try {
    const data=await api(`/api/worlds/${state.world}/events?${query}`);
    if (epoch!==state.epoch || request!==state.eventEpoch) return;
    if (!append) $('events').replaceChildren();
    for (const event of data.events) {
      const article=element('article','event'),body=element('div');
      const title=({birth:'A new life',death:'A life remembered',conception:'A pregnancy begins',infant_death:'Infant loss',feeding:'Food shared'})[event.event_type] || event.event_type;
      body.append(element('p','event-title',title),element('p','event-details',event.details));
      if (event.agent_id && event.agent_name) body.append(personButton(event.agent_id,event.agent_name));
      article.append(element('div','event-time',`Tick ${number(event.tick)}`),body);$('events').append(article);
    }
    if (!append && !data.events.length) $('events').append(element('p','empty','No events recorded for this filter.'));
    state.cursor=data.next_before;button.hidden=!state.cursor;
  } catch(error) {
    if (epoch===state.epoch && request===state.eventEpoch) {
      if (!append) $('events').replaceChildren(element('p','empty',error.message));
      else notice(`Could not load earlier events: ${error.message}`,true);
    }
  } finally {if (epoch===state.epoch && request===state.eventEpoch) button.disabled=false;}
}
async function loadWorld(id) {
  const epoch=++state.epoch;state.world=id;state.personEpoch++;state.eventEpoch++;state.cursor=null;
  const selected=state.selected;state.snapshot=null;state.selected=null;state.tile=[0,0];$('refresh').disabled=true;
  $('people-list').replaceChildren();$('events').replaceChildren();$('more-events').hidden=true;
  $('person-title').textContent='Person details';$('person-details').replaceChildren(element('p','muted','Choose a person to inspect their life and family.'));
  $('tile-details').textContent='Click a tile to inspect it.';
  $('map').getContext('2d').clearRect(0,0,$('map').width,$('map').height);
  for (const key of ['population','adults','children','births','deaths']) $(`stat-${key}`).textContent='—';
  notice('Reading world snapshot…');
  try {
    const data=await api(`/api/worlds/${id}`);
    if (epoch!==state.epoch) return;
    state.snapshot=data;$('world-name').textContent=data.world.name;
    $('world-meta').textContent=`Tick ${number(data.world.current_tick)} · ${(Number(data.world.current_tick)/365).toFixed(1)} simulated years · ${data.world.status}`;
    for (const key of ['population','adults','children','births','deaths']) $(`stat-${key}`).textContent=number(data.summary[key]);
    renderPeople();drawMap();
    notice(`Observed tick ${number(data.world.current_tick)} · Updated ${new Date().toLocaleTimeString()}${data.people_truncated?' · Map and list limited to 5,000 living people.':''}${data.tiles_truncated?' · Showing the first 10,000 terrain tiles.':''}`);
    loadEvents();if (selected) loadPerson(selected);
  } catch(error) {if (epoch===state.epoch) notice(`Could not load world: ${error.message}. Check the viewer server and try Refresh.`,true);}
  finally {if (epoch===state.epoch) $('refresh').disabled=false;}
}
$('search').addEventListener('input',renderPeople);
$('refresh').addEventListener('click',()=>loadWorld($('world-select').value));
$('world-select').addEventListener('change',()=>{state.selected=null;loadWorld($('world-select').value);});
$('event-filter').addEventListener('change',()=>loadEvents());
$('more-events').addEventListener('click',()=>loadEvents(true));
$('map').addEventListener('click',event=>{
  if (!state.snapshot) return;
  const rect=$('map').getBoundingClientRect();
  inspectTile(Math.min(state.columns-1,Math.max(0,Math.floor((event.clientX-rect.left)/rect.width*state.columns))),Math.min(state.rows-1,Math.max(0,Math.floor((event.clientY-rect.top)/rect.height*state.rows))));
});
$('map').addEventListener('keydown',event=>{
  if (!state.snapshot) return;
  const moves={ArrowUp:[0,-1],ArrowRight:[1,0],ArrowDown:[0,1],ArrowLeft:[-1,0]};
  if (moves[event.key]) {event.preventDefault();const [dx,dy]=moves[event.key];inspectTile(Math.max(0,Math.min(state.columns-1,state.tile[0]+dx)),Math.max(0,Math.min(state.rows-1,state.tile[1]+dy)));}
  else if (event.key==='Enter') {event.preventDefault();inspectTile(...state.tile);}
});
async function start() {
  try {
    const data=await api('/api/worlds');
    if (!data.worlds.length) {notice('No worlds yet. Create a world from the terminal, then reload this page.');$('refresh').disabled=true;return;}
    for (const world of data.worlds) {const option=element('option','',world.name);option.value=world.id;$('world-select').append(option);}
    const preferred=new URLSearchParams(location.search).get('world');
    if (data.worlds.some(world=>String(world.id)===preferred)) $('world-select').value=preferred;
    await loadWorld($('world-select').value);
  } catch(error) {notice(`Could not connect: ${error.message}. Reload this page to retry.`,true);$('refresh').disabled=true;}
}
start();
