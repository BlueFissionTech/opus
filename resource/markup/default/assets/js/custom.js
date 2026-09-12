var index = 0;

var me = {
    name: 'Me',
    image: 'freelancer1.jpg',
    title: 'The Freelancer',
    job: 'coder'
}

var handheld = {
    name: 'Matsuri Handheld',
    image: null,
    title: '',
    job: ''
}

var laptop = {
    name: 'Laptop',
    image: null,
    title: '',
    job: ''
}

var strap = {
    name: 'Wrist Strap',
    image: null,
    title: '',
    job: ''
}

var lucas = {
    name: 'Lucas',
    image: null,
    title: 'Virtual Assistant',
    job: 'gopher'
}

var addison = {
    name: 'Addison',
    image: null,
    title: 'Entrepreneur',
    job: 'ceo'
}

var natalie = {
    name: 'Natalie',
    image: 'natalie1.jpg',
    title: 'Barista',
    job: 'ceo'
}

var allen = {
    name: 'Allen',
    image: 'allen1.jpg',
    title: 'Marketer',
    job: 'ceo'
}

var hugh = {
    name: 'Hugh',
    image: 'hugh1.jpg',
    title: 'Entrepreneur',
    job: 'maker'
}
var story = [

    {
        display: 'world',
        method: 'new',
        content: ' ',
        followup: 'pause'
    },
    {
        display: 'world',
        method: 'new',
        content: "Loading Experience. . .",
        followup: 'wait'
    },
    {
        display: 'world',
        method: 'close',
        followup: 'wait'
    },
    {
        display: 'area',
        method: 'new',
        content: "Start Reading.",
        followup: 'Pause'
    },
    {
        display: 'area',
        method: 'add',
        content: "Some smartass once said that ideas are worthless, and he has no idea what he's talking about.",
        followup: 'bgm_play',
        media: {
            music: 'bettogh-orbital-strike.mp3'
        }
    },
    {
        display: 'area',
        method: 'add',
        content: "Every human thing starts off as an idea, and if you have the palate for believing in God, then everything else started as one of His. Fact is, ideas are like money that anyone can print, that some know how to spend, and that a few even know the value of. And like money, it only has real value in circulation. In fact, this economy of ideas is the only thing keeping our tenuous Atlantis from sinking into a proverbial ocean. No. Far from worthless, ideas are substantial. They even cast shadows. When bathed in the light of truth anyway.",
        followup: 'continue'
    },
    {
        display: 'area',
        method: 'add',
        content: "And this world is filled with ideas. Full of shadows.",
        followup: 'pause'
    },
    {
        display: 'area',
        method: 'new',
        content: "I thank Natalie with a nod as she passes a small coffee to me from across the counter. Though I had ordered a large, my wallet and I had a difference of opinion. It's not the only dispute we've had recently. As it stands there's only about an eight percent chance that my apartment door will unlock for me tonight, meaning a ninety-two percent chance of me sleeping on the streets. That's a slightly higher than average probability. I get a five-day grace period on late rent with a fifteen percent penalty for the delay and it's been a business week. That means I need to come up with twenty-three hundred bits before the end of the workday or get creative.",
        followup: 'continue'
    },
    {
        display: 'area',
        method: 'add',
        content: "I'm used to being creative, but my resources with which to problem solve are admittedly scarce right now.",
        followup: 'pause'
    },
    {
        display: 'area',
        method: 'new',
        content: "The possibility of appealing my case with the housing agency crosses my mind for a second. However, the rental process is a soulless system of software and solenoids. You know what I mean. There isn't any bargaining with them. Not without navigating a slalom of automated operators and a labyrinthian backtrace of call transfers until finding myself in the auditory presence of a distant, uncaring, disgruntled customer service representative who, powerless, must still ultimately transfer me again to her manager who will naturally deny my plea for an extension. By that time, my possessions will be put up for auction. No, a better solution is in order.",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        content: "Lucas",
        source: me,
        followup: 'continue'
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'tag',
        content: "I say,",
        followup: 'continue'
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'dialogue',
        content: "Stream to my earpiece.",
        followup: 'wait'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'tag',
        source: lucas,
        content: "My earpiece answers,",
        followup: 'continue'
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'dialogue',
        content: "What would you like to stream?",
        followup: 'continue'
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'tag',
        content: "I'm not sure which device responds but it does it in Lucas' voice.",
        followup: 'wait'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: me,
        content: "Play my music favorites.",
        followup: 'wait'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: lucas,
        content: "Streaming your favorites to your earpiece now.",
        followup: 'bgm_play',
        media: {
            music: 'vyra-summer-nights.mp3'
        }
    },
    {
        display: 'dialogue',
        method: 'close',
        followup: 'continue'
    },
    {
        display: 'area',
        method: 'new',
        content: "My hunt for a good paying gig has taken up the greater part of last month. The couple I've picked up along with some of the repair work I've done has kept INTELLICOM from disconnecting my Plex connection, and I managed to sell enough Clarion to avoid my power being shut off. The price of housing, however, is substantially higher. Anyone picked at random is more likely to have an IP address than a home address. It's been that way since the mass evictions. I'm pretty good at distracting myself from those statistics, though. Just like the stack of unopened bills on my apartment floor, it's not like I could do anything about it anyway. Better to not lose sleep over the numbers.",
        followup: 'pause'
    },
    {
        display: 'area',
        method: 'close',
        followup: 'continue'
    },
    {
        display: 'world',
        method: 'advance',
        followup: 'continue'
    },
    {
        display: 'world',
        method: 'new',
        content: "I smile at the barista. Natalie's a friend and also the owner, but too busy to talk right now. I guess I'm trying to be busy, too. My “office” is one of the cheaper coffee shops that still have counters and humans instead of a corporate clockwork with screens and gears. A daily cup's more affordable than some high-price lease to project the illusion that I'm professional. My clients know I'm professional because I get my work done, not because of some big windowed office or starched business suit that hides my tattoos. They try to sell you on it. Spend an additional seven hundred and fifty bits a month on a fancy glass cage so you can charge more for your services validated by your “overhead.” I'm too busy trying to keep a roof over my head.",
        followup: 'pause'
    },
    {
        display: 'world',
        method: 'new',
        content: "A sip of coffee burns the tip of my tongue as I suspected might happen. I have another sip as would anyone who is dying to feel something strongly enough to validate their own existence. It's an attempt to pinch myself hard enough to wake up from the Kafkaesque nightmare we collectively snored our ways into. Unfortunately, I suspect this is as close to real as I'll ever get. This is the top level. There's no dream to wake up from or simulation to eject out of. Only a network of interconnected simulacra, a waking dream for a sleepwalking society. Of course, when you get bored, tired, or frustrated with the raging dumpster fire that is our world, you can always log into 3rd World.",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: handheld,
        motion: 'shake',
        content: "Bzzz.",
        followup: 'snd_play',
        media: {
            audio: 'Phone-vibrating-message-notification.mp3'
        }
    },
    {
        display: 'dialogue',
        method: 'close',
        followup: 'continue'
    },
    {
        display: 'world',
        method: 'new',
        style: 'note',
        content: "The device in my pocket vibrates and a half-second later the black screen on my wrist strap illuminates with white lettering. I read the words on the display.",
        followup: 'continue'
    },
    {
        display: 'strap',
        method: 'new',
        style: 'tag',
        content: "1 Notification from Cubiqle:",
        followup: 'continue'
    },
    // {
    //     display: 'strap',
    //     method: 'add',
    //     style: 'tag',
    //     content: 'it prints',
    //     followup: 'continue'
    // },
    {
        display: 'strap',
        method: 'new',
        style: 'note',
        content: "your bid has been declined.",
        followup: 'continue'
    },
    {
        display: 'world',
        method: 'new',
        content: "My breath escapes sharply as my strap fades back to black. The simultaneous feeling of chill and fever rushes across my skin starting from my shoulders. It washes up and down my entirety causing me to sink back into my seat from instantaneous fatigue. As I pull my handheld from my pocket, its see-through transoptive glass body pulses to life with a pale glow.",
        followup: 'pause',
    },
    {
        display: 'handheld',
        method: 'new',
        content: "Grasping it firmly in my palm, I flick my thumb across its touch-sensitive display and tap a light blue icon with a large letter C. The display warbles as I access the Cubiqle mod, a popular, low-cost platform for mendicant mercenaries like myself to find gigs. Really, it's like paying a deposit to stay functionally unemployed and dangerously optimistic. A detailed view of my bid dashboard and recent activity loads up on the display.",
        followup: 'pause'
    },
    {
        display: 'handheld',
        method: 'new',
        content: "Out of the thirteen gigs I bid on this week, six were turned down and the rest haven't even been replied to. The indecency of leaving someone hanging for days makes me wonder if I'd even want to work on their projects. Poor communication is a huge red flag for potential clients. I'd, of course, work on them anyway, but that's out of present need. I reply to each of the gigs' chat threads with a line of niceties and a gentle nudge to approve my bid, finishing my first task for the day. Inconspicuous pleading. Next, I reach out to old clients whom I haven't heard from in some time, hoping to scare up old relationships. Then I ask the mod to show me any other gigs that match my skill profile and I bid on all of them without even reading the description to properly estimate the cost. As far as I'm concerned right now, every project would take exactly twenty-three hundred bits to accomplish.",
        followup: 'pause',
    },
    {
        display: 'handheld',
        method: 'close',
        followup: 'continue'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: me,
        content: "Addison,",
        followup: 'continue',
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'tag',
        content: "I begin one message,",
        followup: 'continue',
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'dialogue',
        content: "thank you for the opportunity to be involved in your project. I have extensive experience in the field of enterprise-scale distributed inference and automation services. My portfolio should demonstrate an accomplished history across a wide range of verticals and multiple business models. I look forward to hearing your reply to my bid. I am certain I'm the right fit for your team and can provide what you need for your interactive virtual concierge system software.",
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        content: "(I'll wait for a reply...)",
        source: me,
        followup: 'wait'
    },
    {
        display: 'dialogue',
        method: 'close',
        followup: 'continue'
    },
    {
        display: 'console',
        method: 'new',
        // content: "!function(a,b){\"use strict\";\"function\"==typeof define&&define.amd?define([\"jquery\"],b):\"object\"==typeof exports?module.exports=b(require(\"jquery\")):a.bootbox=b(a.jQuery)}(this,function a(b,c){\"use strict\";function d(a){var b=q[o.locale];return b?b[a]:q.en[a]}function e(a,c,d){a.stopPropagation(),a.preventDefault();var e=b.isFunction(d)&&d.call(c,a)===!1;e||c.modal(\"hide\")}function f(a){var b,c=0;for(b in a)",
        content: "#!JenSS\n& IN com.system\n& REFERENCE BY entities (A library of active simple entity classes)\n! INCLUDE com.system.datatypes\n///\n\n(Introduce the \"entity\" concept)\n& INTRODUCE <entity[class]>\n\n<<<\nAn entity (any object in real space) has\n  an accessible   <quantity>  expressed as $size,\n   an accessible   <matrix>    expressed as $position,\n   an inaccessble  <vector>    expressed as $movement.\n\nAn entity expects size and position.\n\nWhen position is \"changing\"    (This occurs at the start of a new assignment of this property)\n   then $_firstPosition equals position.\n\nWhen position is \"changed\"   (This occurs at the end of a new assignment of this property)\n then vector equals position - _firstPosition.\n\nIf size equals less than 0\n   then position equals nothing.\n>>>\n\n(Introduce the \"agent\" concept based on \"entity\")\n& INTRODUCE agent[entity]\n\n<<<\nAn agent (any entity with behaviors) has\n   a readable      <alphanum>  expressed as $behavior.\n\nAn agent does /perform, which\n  accepts an <alphanum> called $action\n  and sets behavior equal to action\n and creates a new <event> called event\n    and sets event's label to behavior\n    and dispatches event.\n\nWhen agent is \"moving\"\n agent expects an internal <matrix> called $slope\n  and while slope exists\n        position increases by slope.\n\nIf movement's magnitude equals greater than 1000000\n   then slope equals nothing.\n>>>\n\n(Create an agent)\n\n& @Person is an <agent [5, {0,0}]>\n\n<<<\nPerson does perform moving.\nTell me Person's position!\n>>>",
        followup: 'continue'
    },
    {
        display: 'strap',
        method: 'new',
        style: 'tag',
        content: "1 Notification from Cubiqle:",
        followup: 'continue'
    },
    {
        display: 'console',
        method: 'close',
        followup: 'continue'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: addison,
        content: "We appreciate your offer and your skillset,",
        followup: 'continue',
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'tag',
        content: "Addison replies two hours later,",
        followup: 'continue',
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'dialogue',
        content: "but due to investor scrutiny, we cannot associate our start-up or product with a person having your history and must decline your bid. Sorry, and thank you.",
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'close',
        followup: 'continue'
    },
    {
        display: 'world',
        method: 'close',
        followup: 'continue'
    },
    {
        display: 'area',
        method: 'new',
        content: "At least they had the decency to respond, so I return the courtesy. I type “thank you for your time” and send it off. It's at least valuable feedback knowing the reason my bid was turned down, though it's not as if the message said anything elucidating. I can get plenty of work from low-budget, desperate clients that ask too much and pay too late. However, prospects with money and authentically good ideas also have background checks and nervous board members afraid of being connected to anything or anyone that might harm their bottom line. Fair enough, I suppose. I am a criminal, after all.",
        followup: 'pause'
    },
    {
        display: 'area',
        method: 'new',
        content: "I'm also a freelancer. That's my hustle of choice anyway. A new economic species that evolved out of the catastrophe of the Bubble, like the radioactive cockroaches of Chernobyl. There are a few of us here as I look around. Faces pulled toward the compelling glow of our displays and surviving on java, that's both scripts and sips. Several of us freelancers are coders. Human vestiges of what life might have been like if we preserved the Bubble. Unlike the writers and designers, though, our profession isn’t as old as civilization. We, coders, are new. Proof that man is still evolving, and a reminder that we might have been better off if we turned the other way.",
        followup: 'pause'
    },
    {
        display: 'area',
        method: 'new',
        content: "It’s funny. Technology makes us damn near omniscient and omnipresent but completely impotent at accomplishing anything that matters. At least on my resume, my professional specialty is solving problems. Most of those problems, mind you, are caused by other people's “solutions” for a whole other set of problems. That's how our economy works, you know. Chase an endless chain of symptoms caused by the medicines used to treat, but never cure, a root disease. If your products have some unknown consequence, so what? That's someone else's problem, or rather, opportunity. In short, it's only a side-effect if it's mentioned in fine print, but if you type it in bold on the side of the box, it's a feature. We get sold a lot of features. We're blessed.",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'new',
        source: strap,
        motion: 'shake',
        content: "Vrrr.",
        followup: 'snd_play',
        media: {
            audio: 'Phone-vibrating-message-notification.mp3'
        }
    },
    {
        display: 'dialogue',
        method: 'close',
        followup: 'continue'
    },
    {
        display: 'area',
        method: 'new',
        content: "I respond to a shorter, much softer vibration that alerts me at my wrist and I glance down at the display.",
        followup: 'continue'
    },
    {
        display: 'strap',
        method: 'new',
        style: 'note',
        content: "Blood sugar low.",
        followup: 'continue'
    },
    {
        display: 'area',
        method: 'new',
        content: "My Sensys implant is not so subtly reminding me that I haven't eaten all day. Also, I have a condition and a bad habit to neglect it. It's not frequently enough to be a huge scare but a few times I've woken up on the floor head spinning after an unknown interval of unconsciousness. I consider how I should probably make some money for food before I pass out. Despite my best efforts to do legitimate work I'm struggling to survive. I tilt my head back and to the side to sweep my locks from my face so I can clearly see the hopelessness of the situation.",
        followup: 'pause'
    },
    {
        display: 'area',
        method: 'new',
        content: "That's what our progress has achieved for us. Constant, reactionary struggle. All of us live like this, I suppose. From moment to moment, in one single square of the film reel at a time. Some of us, however, can afford to act like we're bigger than the frame. They can pretend, pretty convincingly actually, like they're agents of the scene, or even participants guiding the course of the act. I'm not one of those people. I'm not quite so deluded. I know that the world, in all of its splendor, only extends as far as this quickly cooling cup of black Columbian, my handheld, and me. For all practical purposes, anyway. That's the reality.",
        followup: 'pause'
    },
    {
        display: 'area',
        method: 'new',
        content: "Reality is too heavy a burden for the culture of convenience we designed ourselves, anyway. It shines too brightly for most of us, so I grew content with the dark places and became accustomed to everything being equal levels of shady. It's more comforting to see less, else you might have to come to terms with knowing the cost of convenience. If you know how expensive it is it means you have to start coming up with better ideas to offset the toll. So I spend a lot of my time in the shadier parts of our society. I do my best work there. There's no need to turn a blind eye when it's already pitch black and everything is morally monochromatic muddiness.",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: natalie,
        content: "You've been quiet today,",
        followup: 'continue',
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'tag',
        content: "Natalie says, sneaking up beside me.",
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: me,
        content: "Lucas, pause,",
        followup: 'bgm_pause',
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'tag',
        source: me,
        content: "I look to her,",
        followup: 'continue',
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'dialogue',
        content: "You looked busy. Didn't want to bother you.",
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: natalie,
        content: "Well, I'm here now. What do you have going on today?",
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: me,
        content: "Same old same old. Living the dream, trying to wake up.",
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: natalie,
        content: "That so? I wish I could go the fuck back to sleep.",
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: me,
        content: "Shit,",
        followup: 'continue',
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'tag',
        content: "I say,",
        followup: 'continue',
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'dialogue',
        content: "I might not even have a bed tonight. Looking for gigs now so I can make rent.",
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: natalie,
        content: "Oh, ain't that the constant struggle. Trust me, I get it,",
        followup: 'continue'
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'tag',
        content: "she pauses with a sigh,",
        motion: 'pulse',
        followup: 'continue'
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'dialogue',
        content: "so I'm gonna go ahead and say it. If things fall through I can put you up for a few days. After this weekend, anyway. Just give me a call.",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'tag',
        source: me,
        content: "I turn my body around to her.",
        followup: 'continue'
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'dialogue',
        content: "Thanks, Nat. I'd hate to ask you, though.",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: natalie,
        content: "Figured. That's why I offered before you had to.",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: me,
        content: "If my options run out, I'll keep you in mind.",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: natalie,
        content: "Do that. If I hear about anything happening to you because you chose pavement over me, I'd kick your ass.",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'tag',
        source: me,
        content: "I laugh.",
        followup: 'continue'
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'dialogue',
        content: "Nope, can't have that, can we? How about I say this — I'll take you up if I have to.",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: natalie,
        content: "You'd better. I've got to get back to the counter, good luck on that search!",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: me,
        content: "Okay, I'll let you know. Thanks again,",
        followup: 'continue'
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'tag',
        content: "I answer.",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: me,
        content: "Lucas, resume.",
        followup: 'bgm_play'
    },
    {
        display: 'dialogue',
        method: 'close',
        followup: 'continue'
    },
    {
        display: 'world',
        method: 'new',
        content: "It's hard not to appreciate Natalie and her Saturday Morning cartoon friendship. But, honestly, I wouldn't feel right owing her for that kindness when I have nothing to offer. It stands that I can barely afford the coffee at her shop.",
        followup: 'pause'
    },
    {
        display: 'world',
        method: 'new',
        content: "It's time to get back to work and see how many other bids were shot down. At least I'm still in the best co-working space I've discovered so far. A great spot in the quiet part of downtown. I'm not a coffee aficionado, but I'm an ace at finding a good hole-in-the-wall. Someplace alive enough to keep my mind active but peaceful enough not to hijack it. Also, I can be inconspicuous here. Fade into the background. I hate being noticed, so my favorite seat is right here. Next to the large glass window.",
        followup: 'pause'
    },
    {
        display: 'world',
        method: 'new',
        content: "There's a woman in the corner to the back of the shop and you have to wonder about her. See her? Is she building the next billion-dollar company? Downloading an illegal weapon? Writing a magnum opus screenplay? You never know, with her being buried so secretly back there. No one is concerning themselves with me that way. I'm far too visible. Too comfortable. On a gig not too long ago, I built a surveillance algorithm for a business park. The research was telling, all the things I learned about what people take notice of. Like my tattoos that don't show up against my skin, I draw no attention in plain view. And it turns out I'm at my happiest when people look through me. That's a bit of a rare trait in a world where everyone else is farming for spectators.",
        followup: 'pause'
    },
    {
        display: 'world',
        method: 'new',
        content: "A police UAV buzzes past the window. Militarized air-gear. After the riots were shut down they've been kept down by constant pressure. This deputized sky squadron is the pressure. They have pretty good coverage over the municipality and decent where I live in the outskirts, too. Despite looking hard and heavy as a floating cast-iron skillet, until they're right up on you they're whisper-quiet. But if you've ever heard one in pursuit mode the buzz is terrifying like a swarm of Africanized killer bees. Doesn't even look at me or the homeless woman sitting outside in front of the window. Invisible, the both of us. It moves on after lingering a moment to pan the street with its cameras. There's no human on the other side of that device. A fully autonomous buzzard.",
        followup: 'pause'
    },
    {
        display: 'world',
        method: 'new',
        content: "Across the street, I see a brown-skinned man with a vandyke just standing there in a gray hoodie. I recognize him from yesterday. He was here in the shop for an hour or two. I remember because he was in the back, buried in something like he was trying to stay hidden. For a moment it looked like he was just glancing in through the window, just before the buzzard panned the street, then he turned around. Like he was hiding his face. I can barely tell if it's because I noticed him or because the UAV started to scan. At any rate, he's walking away now, headed into one of the offices on that side of the road. Probably works with some big-dream start-up with a new Plex platform. I wonder if they're hiring.",
        followup: 'pause'
    },
    {
        display: 'world',
        method: 'new',
        content: "The Plex is overflowing with platforms that each start off as a question: “what if...?” That question becomes an idea. A speck of space dust that attracts other specks into a nebulous cloud of theories and information until it becomes dense enough to evolve into a star system. Platforms are the space stations and habitable planets that orbit these big ideas. Each one suspended in a market-approved goldilocks zone with unique resources, climates, and cultures. That's what the Plex is like. Endless space twinkling and sparkling with branded supernovas and quasars that shine at enterprise scale. Some stars, some ideas, are bright. Very bright. But even the brightest still cast those shadows against the ineffable and inconvenient aura of reality.",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: handheld,
        motion: 'shake',
        content: "Bzzz,",
        followup: 'snd_play',
        media: {
            audio: 'Phone-vibrating-message-notification.mp3'
        }
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'tag',
        content: "my handheld calls out to me. I check my wrist strap first.",
        followup: 'wait'
    },
    {
        display: 'strap',
        method: 'new',
        style: 'note',
        content: "1 Notification from Cubiqle",
        followup: 'continue'
    },
    // {
    //     display: 'strap',
    //     method: 'add',
    //     style: 'tag',
    //     content: "it reads",
    //     followup: 'continue'
    // },
    {
        display: 'dialogue',
        method: 'close',
        followup: 'continue'
    },
    {
        display: 'handheld',
        method: 'new',
        content: "The glass display on my handheld flickers on with a pale glow as my hand flies across the table for it. My thumb taps on the waiting notification and it reloads the Cubiqle mod.",
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: allen,
        content: "Glad you touched base! We've got a rush need. Monday deadline. You have an opening this weekend?",
        followup: 'continue'
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'tag',
        content: "Looks like I managed to shake the right tree. The message says all I need to know and just in time. I respond.",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: me,
        content: "Sure thing. Just opened up.",
        followup: 'continue'
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'tag',
        content: "I lie. I didn't just open up any more than a 24-hour drug store at 2 AM. I've been hurting for work.",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'close',
        followup: 'continue'
    },
    {
        display: 'handheld',
        method: 'close',
        followup: 'continue'
    },
    {
        display: 'world',
        method: 'close',
        followup: 'continue'
    },
    {
        display: 'area',
        method: 'new',
        content: "This particular client’s forward-thinking. When she gets in touch she's always antsy for a new algorithm or something, and people like me make it happen. That’s the way it works. Everyone with ideas is on the hunt for the next big thing. They believe the first crash was a fluke and that the Bubble can be rebuilt and stabilized while still sitting on a network of corporations that pump out as much as they suck up. Pipedreams, I say. It was then and it is now. That’s just what happens when you try to build a society on ether. On electricity and data. A kingdom in the cloud. I guess I’m part of the problem, though. Me and all the other people building inference systems in this shop. Did you hear synthetic agents are going to have rights soon? Saw it on the news myself.",
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: handheld,
        motion: 'shake',
        content: "Bzzz.",
        followup: 'snd_play',
        media: {
            audio: 'Phone-vibrating-message-notification.mp3'
        }
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'tag',
        content: "My handheld again. Client's responding. I click the notification.",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: allen,
        content: "Great. Here are the specs. Offer's in the description. Hit 'accept' on the bid if you're up for it.",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'close',
        followup: 'continue'
    },
    {
        display: 'handheld',
        method: 'new',
        content: "I read the text and it looks straightforward enough. The client needs a demo of a concept. Probably a carrot for some investors. It's not the kind of thing I normally, do. Really more like a project Ca$handra would pick up. The price looks good, though. Enough to cover at least two months. This client knows what I ask and there's no need to haggle so I accept the gig. My next check is lined up, I breathe at my normal base rate for the first time since I woke up, and I'm already opening my laptop to set up the project. Only minutes into the task, and my handheld sings with a Mario Bros coin chime. I'm saved! It means some bits were added to my wallet, which in turn means the client's antsier than I thought and already made the first installment. Her name's Allen. Allen gets me.",
        followup: 'pause',
    },
    {
        display: 'handheld',
        method: 'close',
        followup: 'continue'
    },
    {
        display: 'console',
        method: 'new',
        content: "Time is money and I have three days to turn this project in. By the time I fully grok the specs I'm already deep in, pounding away at keys. This module runs an algorithm that predicts where a person might be in the next day or so, then targets an advertisement to them for something that they might want to buy near there. Real subliminal, I’d never have dreamed it up myself. She runs a marketing firm, and it's pretty slick stuff truth be told. I’d find it admirable if I didn’t hate getting sold to so much. All these big corporations run on marketing. Some of them don't even sell anything but have millions to spend on ads somehow. Must have been wrong earlier. Time isn't money. Time is a vault. Attention is money, and the corporations cash it in hand over fist. So, actually, I was wrong twice — the corporations sell themselves, and we pay attention. I need to pay bills.",
        followup: 'pause'
    },
    {
        display: 'console',
        method: 'new',
        content: "Algorithms are just processes, often sitting at the center of those platforms that are so popular. Optimize this. When something happens do that. This one is simple enough, though. At least to someone with my experience. After some time I've made huge progress in this module just in drawing out the basic logic alone. The data types for the inputs, the data shape of the output, the data mining for its collection, the database of its storage, every aspect of the data I could account for. I've even designed the training process for the deep neural net that sits at the core of the inference system. That's really why I do this. To lose myself in the logic. There's a power in unlocking the potential of a device, in pushing the boundaries of code. Though pushing boundaries has its dark sides as well. Just look at the Valley disaster.",
        followup: 'pause'
    },
    {
        display: 'console',
        method: 'new',
        content: "At its root, this module just needs to recognize a pattern and draw it to its logical conclusion. Simply, everyone leaves a pattern, even with the personal information they don’t share. It’s actually what you choose to hide that says more about you. You just have to write the story to fill the gaps. I lay the specs out for this algorithm into steps and make a flow chart. Usually, this stuff can be done with code by itself. For things like “find the most popular news article that is also in the user's favorite categories,” anyway. The issue comes in when they need to do something complex. Something human. Something like “offer sympathy to the user if they seem distraught.” Thing is, computers don't get “distraught.” They don't understand “sympathy.” You've got to teach them what any of this means.",
        followup: 'pause'
    },
    {
        display: 'console',
        method: 'new',
        content: "The only way to keep this module from becoming a hellscape of recursive simulations is for it to think like a person. And if you need a mod to act human, load a cog into it. I start designing the cog that will run the algorithm and it becomes clear that this might be a bit more difficult than I first assumed. That's what I get for optimism. I create a configuration file and change the default settings to suit the project. More rational. Neutrally reactionary. Less emotional. Unexpressive. Low self-interest. Never set a cog to have no self-interest. It'll run itself into the ground trying to fulfill its function and it'll be months before you figure out where the errors are coming from. Don't jack it up the other way, either, if you don't want terminators. Looks all wrapped up so I tell my laptop to update the mod.",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: laptop,
        content: "Save this file?",
        followup: 'wait'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: me,
        content: "Yes,",
        followup: 'continue'
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'tag',
        content: "I confirm",
        followup: 'wait'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: laptop,
        content: "File saved successfully. Build and launch this Cogito now?",
        followup: 'wait'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: me,
        content: "No.",
        followup: 'wait'
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'tag',
        content: "No reason to run an empty cog",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'close',
        followup: 'continue'
    },
    {
        display: 'console',
        method: 'new',
        content: "There's a vital step to making it useful. First, I need to design a new psychograph for it. Almost all of the technology we use is built on psychographs. In fact, it's a necessary component for all the work I do specifically. If you could create a file format for the human soul, this is what it would be. Those cognitive agents wouldn't be getting rights if it weren't for that. I add some qualities and underlying values from my template. Nothing fancy, just a basic seed to build the brain from. Doesn't take long. I save and export it for my cog.",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: laptop,
        content: "Import sample.psy into the project?",
        followup: 'wait'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: me,
        content: "Yes,",
        followup: 'wait'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: laptop,
        content: "Psychograph imported successfully! Build and launch this Cogito now?",
        followup: 'wait'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: me,
        content: "Yes.",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'close',
        followup: 'continue'
    },
    {
        display: 'console',
        method: 'new',
        content: "It's hours in and I don't even notice that the sun has sunken behind the skyline. I've been browsing around on my handheld for a while now. I pick my laptop up to see where the cog is. It's almost done compiling and I've just about earned a wrap-up for the day. Eighty percent complete. Just a bit more and I can head home. Ninety percent. Ninety-nine. One hundred.",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: laptop,
        content: "The Cogito has launched with some errors,",
        followup: 'continue'
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'tag',
        content: "the laptop reads.,",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'close',
        followup: 'continue'
    },
    {
        display: 'console',
        method: 'new',
        content: "Typical. A cog reporting an error is like a virtual nervous breakdown. Means something wasn't compatible between the value table and the logic and it's gone all L7. To know what a man cares about, look at his calendar. To know what a cog cares about, look at its psychographic value table. Programmers like me have to create equations that represent ideas like “productivity,” or “customer satisfaction.” For this algorithm, I'm telling the cog to focus on “curiosity” and “continuity.” The ability to ask questions until the story makes sense and to stitch together a narrative from multiple players. Like I said, harder than I first thought.",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: natalie,
        content: "You look intense, whatever it is you're working on,",
        followup: 'continue',
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'tag',
        content: "Natalie's soft on her feet like a feline,",
        followup: 'continue',
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'dialogue',
        content: "Hope it's paying. Anything I can get you?",
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: me,
        content: "It is, thanks, and no. Nothing much I can think of,",
        followup: 'continue',
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'tag',
        content: "I say, then remember my Sensys alert,",
        followup: 'continue',
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'dialogue',
        content: "Actually, can I have another cup? With cream this time?",
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: natalie,
        content: "Cream's gonna cost you extra today, babe.",
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: me,
        content: "Huh, Why's that?",
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: natalie,
        content: "Shortages. Supply's low on at least a third of our inventory this week.",
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: me,
        content: "Ain't that a son-of-a-bitch. Just black then, please.",
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: natalie,
        content: "It is, and it ain't getting better soon. It's been months of shortages with no end in sight.",
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: me,
        content: "Well, whatcha gonna do? Just gotta make due.",
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: natalie,
        content: "Guess, so. Let me top that off for you.",
        followup: 'wait',
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'tag',
        content: "She takes my cup and walks away. In a minute she's back and sets the coffee down behind the laptop.",
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: me,
        content: "Hey, thanks.",
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: natalie,
        content: "No problem.",
        followup: 'break',
    },
    /*
    {
        display: 'console',
        method: 'new',
        content: "Typical. A cog reporting an error is like a virtual nervous breakdown. Means something wasn't compatible between the value table and the logic and it's gone all L7. To know what a man cares about, look at his calendar. To know what a cog cares about, look at its psychographic value table. Programmers like me have to create equations that represent ideas like “productivity,” or “customer satisfaction.” For this algorithm, I'm telling the cog to focus on “curiosity” and “continuity.” The ability to ask questions until the story makes sense and to stitch together a narrative from multiple players. Like I said, harder than I first thought.",
        followup: 'pause'
    },
    */
    {
        display: 'dialogue',
        method: 'close',
        followup: 'continue'
    },
    {
        display: 'console',
        method: 'close',
        followup: 'continue'
    },
    {
        display: 'handheld',
        method: 'new',
        content: "Noticing the time I decide to pack it up anyway. I grab my handheld to tip Natalie with some bits, confident that more is headed my way once this gig is done. Still on my device, I summon a Port to take me home, pack up my laptop, and head outside. Shame about the shortages hitting the shop. Surprised Natalie's survived so long. A financial injection could solve her problems, at least float her a while, but the Syndicate wouldn’t even give her a loan with a business like this, much less a proper investment. Coffee's not a sexy industry without some biohacking formula or some type of clockwork setup. She's definitely working against the times. Either way, I'm a loyal customer, at least until this place shutters up. I pick up my cup of coffee and take a sip. Of course, it's too hot and I keep sipping anyway. It's good, though, and cheap rent.",
        followup: 'pause'
    },
    {
        display: 'handheld',
        method: 'close',
        followup: 'continue'
    },
    {
        display: 'area',
        method: 'new',
        content: "The Port is waiting for me by the time I get out of the shop. A current-gen ProXim auto-cab with its glossy, glowing LED surface flashing advertisements in quick, animated succession. I don't worry too much about the cost of this particular convenience as it blinks and hums in front of me. Shoot, I have other costs to worry about anyway. For example, the meter’s running already. More bits. I don’t sweat it and just get in. It’s just trying to earn its living. After all, this auto-cab's going to be an American citizen soon.",
        followup: 'pause'
    },
    {
        display: 'area',
        method: 'close',
        followup: 'continue'
    },
    {
        display: 'world',
        method: 'advance',
        followup: 'bgm_play',
        media: {
            music: 'aries-beats-neowise.mp3'
        }
    },
    {
        display: 'world',
        method: 'new',
        content: "As it travels, red and blue lights flicker on outside, indicating a bevy of available restaurants, shops, and freshly open bars while storefront windows reflect the Port's outer advertisements back to me. Dusk swallows the horizon and the streets begin to fill as young people rush in for a night of libations and licentiousness. On any other night, those lights would be riot cops and the streets filled with protestors. A police buzzard floats by innocuously, just doing its rounds. Looks like it's just fun for them tonight. These people deserve their alcohol-drenched, drug-fueled escape. If you ask me, it's the only way that the real world has any charm. Though personally, those nightclub days are behind me now. At least when it comes to entering them through the front door.",
        followup: 'pause'
    },
    {
        display: 'world',
        method: 'new',
        content: "On some nights I still run the sound at a couple of these venues. You get to see it from in the back or up in a booth. No one is celebrating or dancing the dance of life. They reflect the desperation of people doing their damndest to forget how bad they have it and to ignore how much worse off others still do. Especially that second one. It's easy to feel like you deserve a break, but harder to do when thinking about the panhandler that stopped you on your way in. When begging nowadays, you don't even need a sign or a story. Thing is, everyone knows the story. We all live it together. Of course they don't have a job or a home, and you already know why. Chances are you've been there or came hella close. And if not, if you're honest with yourself, then you know that but by the grace of God alone goeth thee.",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: handheld,
        content: "Ping Ping.",
        motion: 'shake',
        followup: 'wait'
    },
    {
        display: 'dialogue',
        method: 'close',
        followup: 'continue'
    },
    {
        display: 'handheld',
        method: 'new',
        content: "My handheld sings a “ping-pongy” song that surprises me. Means there's a call coming in. I check it. A preview of Hugh's video feed shows up on my display and his eyebrows rise and fall like he's expecting to be happy to see me. He only ever calls when he needs me to pick up a shift. Good day for income, I suppose. My thumb taps the green button rendered on the glass and the session starts.",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'tag',
        source: me,
        content: "I speak up first.",
        followup: 'continue',
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'dialogue',
        content: "What's up?",
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'new',
        source: hugh,
        style: 'dialogue',
        content: "Hey, boo. It's dark, I can't see your face. You alone?",
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: me,
        content: "Yeah. In a Port. On my way home.",
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: hugh,
        content: "Mmm, okay. gotcha. Hey, are you open tomorrow?",
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: me,
        content: "Just picked up a coding gig, but I got a weekend on it. What do you need?",
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: hugh,
        content: "Lovely! Okay, I just got my hands on a ProXim Personal 880 I've got to move fast. I need it better than factory fresh by the end of the day so it's ready for its next owner. Can you—",
        followup: 'wait',
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: me,
        content: "I'm going to remind you that this call isn't encrypted. Also, I'm in a Port, you know these things have mics and cameras on all the time.",
        motion: 'pulse',
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: hugh,
        content: "Boo, don't tell me how to conduct business. I haven't said anything you need to worry about, and you're the one over here sounding suspicious. Reset yourself. Anyway, can you come to the shop tomorrow?",
        motion: 'no',
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: me,
        content: "How early?",
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: hugh,
        content: "We're gonna hit it at the top, come as early as you can. The going's forty-eight thousand bits, so I'll have a chunk headed your way once the deal's done dot ee-ecks-ee. So, I'll see you?",
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: me,
        content: "Yeah, I can come through.",
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: hugh,
        content: "That's why I love you. You're always taking care of me. Okay, don't forget, bye-ee!",
        followup: 'pause',
    },
    {
        display: 'dialogue',
        method: 'close',
        followup: 'continue'
    },
    {
        display: 'handheld',
        method: 'new',
        content: "The session disconnects and my handheld slowly dims out to darkness. Seems it's turning out to be a busy weekend. My handheld lights up again with a message box.",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        source: handheld,
        content: "Looks like you are planning to meet with Hugh at the shop tomorrow morning. Would you like to add a reminder to your calendar?",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'tag',
        content: "I tap",
        source: me,
        followup: 'continue'
    },
    {
        display: 'dialogue',
        method: 'add',
        content: "Yes",
        followup: 'continue'
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'tag',
        content: "and the message box closes.",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'close',
        followup: 'continue'
    },
    {
        display: 'handheld',
        method: 'close',
        followup: 'continue'
    },
    {
        display: 'world',
        method: 'new',
        content: "The cab brakes, starts, swerves, and navigates around the pedestrian crowd with the precision of a military drone strike and none of the civilian casualties. It even avoids the occasional black hoodie that whizzes through traffic, and one that deftly leaps over the top. Crazy Tracers. Anyway, ProXim could never afford a driver algorithm with errors. This vehicle would never hit any of them. They're more likely to catch bullets tonight than wind up on the grill of a street gear.",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'dialogue',
        content: "Bzzz,",
        motion: 'shake',
        source: handheld,
        followup: 'snd_play',
        media: {
            audio: 'Phone-vibrating-message-notification.mp3'
        }
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'tag',
        content: "my handheld says. Another notification comes in.",
        followup: 'wait'
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'tag',
        content: "I glance at my strap display,",
        followup: 'continue'
    },
    {
        display: 'strap',
        method: 'new',
        style: 'note',
        content: "Your order from Wimzee has been delivered!",
        followup: 'continue'
    },
    {
        display: 'dialogue',
        method: 'close',
        followup: 'continue'
    },
    {
        display: 'world',
        method: 'new',
        content: "Must be the new Matsuri Handheld 8A that I ordered! Means I get to play with a new toy when I get back to the apartment. An expensive toy and the reason I almost couldn't pay rent this month. It's more business than pleasure, to be honest. These devices are required tools for my work. I'm not much without them. Really, neither is the world. 3rd World has the right idea. Curating the entire experience of living into digestible bites is preferable to raw and unedited versions of reality by leagues. Devices are our windows into that world of clean edges and smooth curves and God bless them for it. There's still a bit more ride left to go so that Wimzee package will have to wait, just as long as it isn't stolen before I get home. I grab my handheld and open my home security mod to check the front door camera, just making sure my package is still there. I see nothing. If the package is there, then it was put down just out of frame.",
        followup: 'pause'

    },
    {
        display: 'world',
        method: 'new',
        content: "At least a hundred displaced bodies lie on the sidewalks, stepped past and walked over by a few thousand others just trying to have a good time. The well-dressed ones clogging the crosswalk more than likely work for one of the Syndicate start-ups, or do remote work for a company in the Tri or one of the surviving post Bubble companies out West. A few at the corners are companions looking for a john or hired by a club to attract any wayward eyes that look up from their handhelds long enough to see and follow a pretty face.",
        followup: 'pause'

    },
    {
        display: 'world',
        method: 'new',
        content: "My wrist strap glows. A notification shows up from David this time.",
        followup: 'continue'
    },
    {
        display: 'strap',
        method: 'new',
        style: 'note',
        content: "Got anything going down tonight?",
        followup: 'continue'
    },
    {
        display: 'dialogue',
        method: 'new',
        style: 'tag',
        content: "I dictate my reply to my strap,",
        source: me,
        followup: 'continue'
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'dialogue',
        content: "Just work til I stop. What's up?",
        followup: 'continue'
    },
    {
        display: 'dialogue',
        method: 'add',
        style: 'tag',
        content: "It types and sends the response.",
        followup: 'pause'
    },
    {
        display: 'dialogue',
        method: 'close',
        followup: 'continue'
    },
    {
        display: 'world',
        method: 'new',
        content: "Police presence, human and gear alike, is heavy this evening, doubtlessly looking for agitators. The municipality spent a substantial budget on rebuilding and the area businesses aren't excited about the prospect of calling their insurance providers about another broken window they won't pay for. No, they are determined to scare away another riot before the idea even pops into these kids' rebellious heads. The vehicle slows to a coasting speed and I realize we're approaching a roadblock. My handheld is permanently set to “deny all connections” since it doesn't count as an illegal search if your device willingly latches on to a friendly-looking police checkpoint beacon. After all, maybe they don't actually want to rummage through your private conversations. Maybe they just want to share a photo announcement of Deputy Gill's thirty-fifth birthday bash with you or some bullshit.",
        followup: 'pause'

    },
    {
        display: 'world',
        method: 'new',
        content: "The knife strapped to my thigh is technically legal, though probably not how I got it. I'd love to not have to answer any questions about it, though. Grabbing my laptop bag I secure the straps and make sure it's zipped tight and the water-proof lip is folded down. Much like with a device, it's fair game if they can easily look inside. If a cop peeks in at a checkpoint and sees any “suspicious” items in your bag, they can command the Port to pull over, order you out, and dig through your bags without penalty. Better to make sure they can't see anything inside the bag at all. This time it pays to tuck your things away and bury your secrets. At least that way there are no legal grounds on which I can be called out for it.",
        followup: 'pause'

    },
    {
        display: 'world',
        method: 'new',
        content: "I bundle up my possessions and sit in the vehicle looking natural in that sort of way that entirely looks like something's up. I don't care this time. Sure, I look less discrete and stick out a little more. A casually open bag in the seat beside me would indeed look more innocent, but just for fun, the checkpoint officer might say he sees an illegal weapon or a bottle of dubious pills or any other faux fiction that gets his hand into my possessions. The worst part, I can't remember if I happen to actually have either on my person today. Better to find out in the privacy of my apartment. The cab rolls up and pauses just long enough for the checkpoint monitor to remote scan my ID. Nothing comes up. Not for what they're looking for, anyway. Still, the auto-cab doesn't pick up speed but instead just sits there for several more uncomfortable seconds. Of course I get picked for a “random inspection” by the recommender. Typical.",
        followup: 'pause'

    },
    {
        display: 'world',
        method: 'new',
        content: "An officer peeks inside to see me with my arms crossed and eyes half-closed but perceptive. My bag is on the opposite side of my body from him. He taps the glass and points down. My first instinct is to shake my head, lean back, and close my eyes, but I don't, knowing damn well that this cab won't move until he checks the all-clear on the monitor. I don't want any trouble tonight, so I press the button to roll down the window glass. He bends over, peeks his head in, and squints while panning a flashlight across the inside cabin. He's silent and so am I. I've got nothing to say to him. With one final pass, he shines the light in my face, and out of resentment alone I don't blink, despite the searing near laser blast of white LED in my pupils. Then he pulls his head out and stands back up. I take a moment to relax as the monitor blinks green and the cab responds. It speeds and moves on leaving the checkpoint behind. Then it zooms past the last shop in the district and we pass through the historic homes on our way to the river.",
        followup: 'pause'

    },
    {
        display: 'world',
        method: 'advance',
        followup: 'continue'
    },
    {
        display: 'world',
        method: 'new',
        content: "Vivid color is swapped out for the amber and silver-white alternation of the halogen and LED street lamps lining the adjacent sidewalk. It takes a left, goes a mile, hangs a right, the bridge is down so it continues. The riverfront lights shimmer on the edge of the water and the wheels beneath me hum over the vibrating grate of the bridge. On the other end of this floating outstretched metal roadway are a series of business parks and workforce apartments in a glowing row of monolithic high rises. That skyline flickers with its own lights, also bouncing their illumination off of the river's surface. They speak a different language, though. Downtown, the lights glow in the dialect of fast-talking street buskers. These have the same accent as hospital fluorescents and airport terminal windows at night. On one side, vibrant and alive. On the other, sterile, clinical, and pragmatic.",
        followup: 'pause'

    },
    {
        display: 'world',
        method: 'new',
        content: "I close my eyes for I don't know how many minutes and realize my head is swimming and it's this that makes me realize I should have paid extra for cream. Despite that, my situation has vastly improved from where it was this morning. I could have stayed home, have them have to force me out to evict me, but when I'm there I feel the opposite of productive. The air just isn't clear like it is in Natalie's shop. The engine winds down and I'm at my apartment. The ride was so perfect and quiet I forget I'm in the cab. I step out of the Port and say thanks without knowing why. ",
        followup: 'pause'
    },
    {
        display: 'world',
        method: 'close',
        followup: 'continue'
    },
    {
        display: 'area',
        method: 'new',
        content: "End of File...",
        followup: 'done'
    }

];

$(document).on('click', '.playBtn', function() {
    $(this).prop('disabled', true).fadeOut();
    $(document).trigger('reader.progress');
});

$('#bgmToggle').on('click', function(e) {
    $('#musicPlayer').trigger("pause");
    /*e.preventDefault();
    $('#musicPlayer').click(function() {
      if (this.paused == false) {
          this.pause();
      } else {
          this.play();
      }
    });*/
});

jQuery(document).ready(function() {
    // $('.chat-window').hide();

	var f = document.getElementById('blink');
	setInterval(function() {
		f.style.display = (f.style.display == 'none' ? '' : 'none');
	}, 400);

	jQuery('.warble').attr('data-text', function() {
  		return jQuery(this).text();
	});

    RetroReader.init();
    function process() {
        RetroReader.process(story[index]);
    }
    $(document).on('reader.progress', process);
    $(document).trigger('reader.progress');
});
