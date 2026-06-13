  (function () {
    var saved = localStorage.getItem('theme')
    var modes = ['auto', 'light', 'dark']

    function getEffective(mode) {
      if (mode === 'light' || mode === 'dark') return mode
      return matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
    }

    function apply(mode) {
      var eff = getEffective(mode)
      if (mode === 'auto') {
        delete document.documentElement.dataset.theme
      } else {
        document.documentElement.dataset.theme = mode
      }
      // Update meta theme-color tags
      var metas = document.querySelectorAll('meta[name="theme-color"]')
      var color = eff === 'dark' ? '#000000' : '#ffffff'
      metas.forEach(function (m) {
        m.setAttribute('content', color)
      })
      // Update toggle button icons
      var icons = {auto: 'monitor', light: 'sun', dark: 'moon'}
      document.querySelectorAll('.theme-toggle use').forEach(function (u) {
        u.setAttribute('href', '#icon-' + icons[mode])
      })
    }

    window.cycleTheme = function () {
      var cur = localStorage.getItem('theme') || 'auto'
      var next = modes[(modes.indexOf(cur) + 1) % modes.length]
      if (next === 'auto') {
        localStorage.removeItem('theme')
      } else {
        localStorage.setItem('theme', next)
      }
      apply(next)
    }

    // Listen for OS theme changes to update when in auto mode
    matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
      apply(localStorage.getItem('theme') || 'auto')
    })

    apply(saved || 'auto')
  })()
      document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'))
      document.querySelectorAll('.auth-panel').forEach(p => p.classList.remove('active'))
      document.querySelector('#tab-' + tab).classList.add('active')
      document.querySelectorAll('.auth-tab').forEach(t => {
        if (t.textContent.toLowerCase() === tab) t.classList.add('active')
      })
    }
    function createElement(tag, attrs, ...children) {
      const e = document.createElement(tag)
      if (attrs) for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, v)
      e.append(...children.filter(Boolean))
      return e
    }

    function div(attrs, ...children) {
      return createElement('div', attrs, ...children)
    }

    function span(attrs, ...children) {
      return createElement('span', attrs, ...children)
    }

    function text(t) {
      return document.createTextNode(t)
    }

    const isSlowDevice = navigator.hardwareConcurrency <= 4 || navigator.deviceMemory <= 2

    function icon(name) {
      const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg')
      svg.setAttribute('class', 'icon')
      const use = document.createElementNS('http://www.w3.org/2000/svg', 'use')
      use.setAttribute('href', '#icon-' + name)
      svg.appendChild(use)
      return svg
    }

    function morph(from, to) {
      if (from.nodeType !== to.nodeType) {
        from.replaceWith(to)
        return
      }
      if (from.nodeType === Node.TEXT_NODE || from.nodeType === Node.COMMENT_NODE) {
        if (from.nodeValue !== to.nodeValue) from.nodeValue = to.nodeValue
        return
      }
      if (from.nodeType === Node.ELEMENT_NODE) {
        if (from.tagName !== to.tagName) {
          from.replaceWith(to)
          return
        }
        syncAttributes(from, to)
        syncChildren(from, to)
        return
      }
      from.replaceWith(to)
    }

    function syncAttributes(from, to) {
      for (const {name} of Array.from(from.attributes)) {
        if (!to.hasAttribute(name)) from.removeAttribute(name)
      }
      for (const {name, value} of Array.from(to.attributes)) {
        if (from.getAttribute(name) !== value) from.setAttribute(name, value)
      }
    }

    function syncChildren(from, to) {
      const fromKids = Array.from(from.childNodes)
      const toKids = Array.from(to.childNodes)
      const commonLen = Math.min(fromKids.length, toKids.length)
      for (let i = 0; i < commonLen; i++) {
        morph(fromKids[i], toKids[i])
      }
      for (let i = fromKids.length - 1; i >= toKids.length; i--) {
        from.removeChild(fromKids[i])
      }
      for (let i = commonLen; i < toKids.length; i++) {
        from.appendChild(toKids[i])
      }
    }

    function toggleSidebar() {
      document.getElementById('sidebar').classList.toggle('open')
    }

    const messages = document.getElementById('messages')
    const loadMore = document.getElementById('load-more')
    const usersList = document.querySelector('.users')
    const badge = document.getElementById('new-messages-badge')
    const form = document.getElementById('chat-form')
    const input = form.querySelector('input[name="message"]')
    const replyPreview = document.getElementById('reply-preview')
    const myUsername = CONFIG.myUsername;
    const csrfToken = CONFIG.csrfToken;

    let lastId = 0
    let oldestId = 0
    let hasMore = true
    let atBottom = true
    let unread = 0
    let polling = false
    let replyTo = null

    function scrollToBottom() {
      messages.scrollTop = messages.scrollHeight
      unread = 0
      badge.style.display = 'none'
    }

    function setReply(id, username, body) {
      replyTo = id
      const preview = replyPreview.querySelector('.reply-preview-text')
      preview.innerHTML = ''
      preview.append(
        span({class: 'reply-preview-author'}, text(username)),
        text(body.replace(/\n/g, ' ').substring(0, 100))
      )
      replyPreview.classList.add('active')
      input.focus()
    }

    function cancelReply() {
      replyTo = null
      replyPreview.classList.remove('active')
    }

    messages.addEventListener('scroll', () => {
      atBottom = messages.scrollTop + messages.clientHeight >= messages.scrollHeight - 30
      if (atBottom) {
        unread = 0
        badge.style.display = 'none'
      }
    }, {passive: true})

    badge.addEventListener('click', scrollToBottom)

    messages.addEventListener('click', (e) => {
      // Click on reply quote scrolls to original
      const quote = e.target.closest('.reply-quote')
      if (quote) {
        const origId = quote.dataset.replyTo
        const orig = messages.querySelector(`[data-id="${origId}"]`)
        if (orig) {
          orig.scrollIntoView({behavior: 'smooth', block: 'center'})
          orig.style.background = 'var(--bg-reply)'
          setTimeout(() => orig.style.background = '', 1500)
        }
        return
      }
      // Timestamp click copies id
      const time = e.target.closest('.message-time')
      if (time) {
        const id = time.closest('.message')?.dataset.id
        if (id) navigator.clipboard.writeText(id)
        return
      }
      // Skip links and username mentions
      if (e.target.closest('a') || e.target.closest('[data-username]')) return
      // Skip if user is selecting text
      if (window.getSelection().toString()) return
      // Click anywhere on message → reply
      const msg = e.target.closest('.message')
      const author = msg?.querySelector('.message-author')?.textContent || ''
      const textNode = msg?.querySelector('.message-text')?.textContent || ''
      if (msg && author.length > 0 && textNode.length > 0) {
        setReply(msg.dataset.id, author, textNode)
      }
    })

    addEventListener('click', (e) => {
      const name = e.target.closest('[data-username]')
      if (name) {
        const username = name.dataset.username
        input.value = input.value.trimEnd() + (input.value ? ' ' : '') + username + ' '
        input.focus()
      }
    })

    function formatTime(iso) {
      const d = new Date(iso)
      return d.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'})
    }

    function findReplySource(id) {
      const el = messages.querySelector(`[data-id="${id}"]`)
      if (!el) return null
      const author = el.querySelector('.message-author')?.textContent || ''
      const body = el.querySelector('.message-text')?.textContent || ''
      return {author, body}
    }

    function fillQuote(quote, src) {
      quote.innerHTML = ''
      quote.append(
        span({class: 'reply-quote-author'}, text(src.author)),
        text(src.body.replace(/\n/g, ' ').substring(0, 100))
      )
    }

    async function fetchReplySource(id, quote) {
      try {
        const res = await fetch('?api=message&id=' + id)
        const data = await res.json()
        if (data.username) {
          const body = /^\[img:[a-f0-9]{64}\]$/.test(data.message.trim()) ? '[GIF]' : data.message
          fillQuote(quote, {author: data.username, body})
        }
      } catch (e) {
        console.error(e)
      }
    }

    function isEmojiOnly(str) {
      const trimmed = str.trim()
      const emojis = trimmed.match(/\p{Emoji_Presentation}|\p{Emoji}\uFE0F/gu)
      return emojis && emojis.length >= 1 && emojis.length <= 3 && emojis.join('') === trimmed
    }

    function renderMessageText(content) {
      const el = span({class: 'message-text'})
      const t = content.trimStart()
      if (t.startsWith('#')) {
        content = t.slice(1)
        el.classList.add('big-message')
      } else if (t.startsWith('~~')) {
        content = t.slice(2)
        el.classList.add('wave-message')
        const text = content
        el.textContent = ''
        for (let i = 0; i < text.length; i++) {
          const ch = document.createElement('span')
          ch.textContent = text[i]
          ch.className = 'wave-char'
          ch.style.animationDelay = (i * 0.06) + 's'
          el.appendChild(ch)
        }
        return el
      } else if (t.startsWith('~')) {
        content = t.slice(1)
        el.classList.add('gradient-message')
      } else if (t.startsWith('^^')) {
        content = t.slice(2)
        el.classList.add('cold-message')
        let coldActive = true
        const len = content.length
        const baseDelay = Math.max(10, 120 - len * 2)
        const spawnSnow = () => {
          if (!coldActive || !el.isConnected) return
          const p = document.createElement('span')
          p.className = 'snow-particle'
          const size = 2 + Math.random() * 3
          p.style.width = size + 'px'
          p.style.height = size + 'px'
          p.style.left = (Math.random() * 100) + '%'
          p.style.top = (-2 + Math.random() * 4) + 'px'
          p.style.animationDuration = (0.8 + Math.random() * 1) + 's'
          el.appendChild(p)
          p.addEventListener('animationend', () => p.remove())
          setTimeout(spawnSnow, baseDelay + Math.random() * baseDelay)
        }
        setTimeout(spawnSnow, Math.random() * 100)
        if (isSlowDevice) setTimeout(() => {
          coldActive = false
        }, 5000)
      } else if (t.startsWith('^')) {
        content = t.slice(1)
        el.classList.add('fire-message')
        let fireActive = true
        const spawnParticle = () => {
          if (!fireActive || !el.isConnected) return
          const p = document.createElement('span')
          p.className = 'fire-particle'
          const colors = ['#fff200', '#ff8c00', '#ff4500', '#ff2400']
          p.style.background = colors[Math.random() * colors.length | 0]
          const size = 2 + Math.random() * 4
          p.style.width = size + 'px'
          p.style.height = size + 'px'
          p.style.left = (Math.random() * 100) + '%'
          p.style.bottom = (-2 + Math.random() * 4) + 'px'
          p.style.animationDuration = (0.5 + Math.random() * 0.8) + 's'
          el.appendChild(p)
          p.addEventListener('animationend', () => p.remove())
          const delay = 30 + Math.random() * 60
          setTimeout(spawnParticle, delay)
        }
        setTimeout(spawnParticle, Math.random() * 100)
        if (isSlowDevice) setTimeout(() => {
          fireActive = false
        }, 5000)
      } else if (t.startsWith('__')) {
        content = t.slice(2)
        el.classList.add('underline-message')
      } else if (t.startsWith('_')) {
        content = t.slice(1)
        el.classList.add('italic-message')
      } else if (t.startsWith('*')) {
        content = t.slice(1)
        el.classList.add('bold-message')
      } else if (isEmojiOnly(content)) {
        el.classList.add('emoji-only')
      }
      const parts = content.split(/(\[img:[a-f0-9]{64}])/)
      for (const part of parts) {
        const m = part.match(/^\[img:([a-f0-9]{64})]$/)
        if (m) {
          const img = createElement('img', {class: 'message-image', src: '?file=' + m[1], loading: 'lazy'})
          img.onload = () => {
            if (atBottom) scrollToBottom()
          }
          el.appendChild(img)
          el.appendChild(span({'style': 'display: none'}, text('[GIF]')))
        } else if (part) {
          el.appendChild(text(part))
        }
      }
      return el
    }

    function renderMessage(msg) {
      if (msg.kind === 'effect') {
        if (Math.abs(Date.now() - new Date(msg.created_at).getTime()) <= 2000) {
          const fx = msg.message
          if (fx === 'shake' || fx === 'flip' || fx === 'blur' || fx === 'disco' || fx === 'wave') {
            const cls = 'world-' + fx
            document.body.classList.add(cls)
            document.body.addEventListener('animationend', function handler(e) {
              if (e.target === document.body && e.animationName === cls) {
                document.body.classList.remove(cls)
                document.body.removeEventListener('animationend', handler)
              }
            })
          } else if (fx === 'blackout') {
            const overlay = document.createElement('div')
            overlay.className = 'blackout-overlay'
            document.body.appendChild(overlay)
            overlay.addEventListener('animationend', () => overlay.remove())
          } else if (fx === 'flash') {
            const overlay = document.createElement('div')
            overlay.className = 'flash-overlay'
            document.body.appendChild(overlay)
            overlay.addEventListener('animationend', () => overlay.remove())
          } else if (fx === 'matrix') {
            const c = document.createElement('canvas')
            c.className = 'matrix-canvas'
            c.width = window.innerWidth
            c.height = window.innerHeight
            document.body.appendChild(c)
            const ctx = c.getContext('2d')
            const cols = Math.floor(c.width / 14)
            const drops = Array(cols).fill(0)
            const chars = 'アイウエオカキクケコサシスセソタチツテトナニヌネノハヒフヘホマミムメモヤユヨラリルレロワヲン0123456789'
            const iv = setInterval(() => {
              ctx.fillStyle = 'rgba(0,0,0,0.05)'
              ctx.fillRect(0, 0, c.width, c.height)
              ctx.fillStyle = '#0f0'
              ctx.font = '14px monospace'
              for (let i = 0; i < cols; i++) {
                const ch = chars[Math.random() * chars.length | 0]
                ctx.fillText(ch, i * 14, drops[i] * 14)
                if (drops[i] * 14 > c.height && Math.random() > 0.975) drops[i] = 0
                drops[i]++
              }
            }, 40)
            setTimeout(() => {
              clearInterval(iv)
              c.remove()
            }, 20000)
          } else if (fx === 'confetti') {
            const colors = ['#ff0080', '#ff8c00', '#40e0d0', '#7b68ee', '#ff4444', '#44ff44', '#ffff00']
            for (let i = 0; i < 80; i++) {
              const p = document.createElement('div')
              p.className = 'confetti-piece'
              p.style.left = (Math.random() * 100) + 'vw'
              p.style.top = (-10 - Math.random() * 20) + 'px'
              p.style.background = colors[Math.random() * colors.length | 0]
              p.style.borderRadius = Math.random() > 0.5 ? '50%' : '0'
              p.style.width = (5 + Math.random() * 8) + 'px'
              p.style.height = (5 + Math.random() * 8) + 'px'
              p.style.animationDuration = (2 + Math.random() * 2) + 's'
              p.style.animationDelay = (Math.random() * 1.5) + 's'
              document.body.appendChild(p)
              p.addEventListener('animationend', () => p.remove())
            }
          } else if (fx === 'rain') {
            let spawned = 0
            const spawnRain = () => {
              if (spawned >= 120) return
              const d = document.createElement('div')
              d.className = 'rain-drop'
              d.style.left = (Math.random() * 100) + 'vw'
              d.style.top = (-10 - Math.random() * 30) + 'px'
              d.style.height = (15 + Math.random() * 25) + 'px'
              d.style.animationDuration = (0.4 + Math.random() * 0.4) + 's'
              document.body.appendChild(d)
              d.addEventListener('animationend', () => d.remove())
              spawned++
              setTimeout(spawnRain, 60)
            }
            spawnRain()
          } else if (fx === 'reload') {
            setTimeout(() => location.reload(), 2100)
          }
        }
        return null
      }
      if (msg.kind === 'delete') {
        for (const id of JSON.parse(msg.message)) {
          const el = messages.querySelector(`[data-id="${id}"]`)
          if (el) el.remove()
        }
        return null
      }
      if (msg.kind === 'system') {
        const sysEl = div({class: 'message system', 'data-id': msg.id},
          text(msg.message)
        )
        sysEl.appendChild(
          span({class: 'message-actions'},
            span({class: 'message-time'}, text(formatTime(msg.created_at)))
          )
        )
        return sysEl
      }
      const isMention = new RegExp('\\b' + myUsername + '\\b', 'i').test(msg.message)
      const isShadowBanned = msg.shadow_banned === true
      const el = div({
        class: 'message' + (isMention ? ' mention' : '') + (isShadowBanned ? ' shadow-banned' : ''),
        'data-id': msg.id
      })
      // Reply quote
      if (msg.reply_to) {
        const src = findReplySource(msg.reply_to)
        const quote = div({class: 'reply-quote', 'data-reply-to': msg.reply_to})
        if (src) {
          fillQuote(quote, src)
        } else {
          quote.append(text('...'))
          fetchReplySource(msg.reply_to, quote)
        }
        el.appendChild(quote)
      }
      el.append(
        span({class: 'message-actions'},
          span({class: 'message-time'}, text(formatTime(msg.created_at)))
        ),
        span({
          class: 'message-author',
          'data-username': msg.username, ...(msg.color ? {style: 'color:' + msg.color} : {})
        }, text(msg.username)),
        renderMessageText(' ' + msg.message),
      )
      return el
    }

    function rankIcon(rank) {
      if (rank < 1 || rank > 9) return null
      const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg')
      svg.setAttribute('class', 'rank-icon rank-' + rank)
      const title = document.createElementNS('http://www.w3.org/2000/svg', 'title')
      title.textContent = 'Rank ' + rank
      svg.appendChild(title)
      const use = document.createElementNS('http://www.w3.org/2000/svg', 'use')
      use.setAttribute('href', '#icon-rank-' + rank)
      svg.appendChild(use)
      return svg
    }

    const onlineUsers = new Set()
    const lastOnline = new Map()
    let firstPoll = true

    function addSystemMessage(msg) {
      const time = new Date().toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'})
      const el = div({class: 'message system'}, text(msg))
      el.appendChild(
        span({class: 'message-actions'},
          span({class: 'message-time'}, text(time))
        )
      )
      messages.appendChild(el)
      if (atBottom) scrollToBottom()
    }

    function renderUser(user, online, label) {
      return div({class: 'user'},
        span({class: 'status-dot' + (online ? ' online' : '')}),
        span(
          {class: 'user-name', 'data-username': user.username, ...(user.color ? {style: 'color:' + user.color} : {})},
          text(user.username),
          rankIcon(user.rank)
        ),
        span({class: 'user-status'}, text(label))
      )
    }

    function renderUsers(users) {
      const now = Date.now()
      const current = new Set()
      const top = []
      const bottom = []
      for (const u of users) {
        const ago = now - new Date(u.last_seen).getTime()
        if (ago <= 60000) {
          top.push({...u, ago})
          current.add(u.username)
        } else {
          bottom.push({...u, ago})
        }
      }
      if (!firstPoll) {
        for (const name of current) {
          if (!onlineUsers.has(name)) {
            const last = lastOnline.get(name)
            if (!last || now - last > 600000) addSystemMessage(name + ' joined')
          }
        }
      }
      firstPoll = false
      onlineUsers.clear()
      for (const name of current) {
        onlineUsers.add(name)
        lastOnline.set(name, now)
      }
      const newUsersList = div({class: 'users'})
      for (const u of top) {
        newUsersList.appendChild(renderUser(u, true, u.status || 'online'))
      }
      bottom.sort((a, b) => new Date(b.last_seen) - new Date(a.last_seen))
      for (const u of bottom) {
        const s = Math.round(u.ago / 1000)
        let label = s + 's ago'
        if (s >= 3600) label = Math.floor(s / 3600) + 'h ago'
        else if (s >= 60) label = Math.floor(s / 60) + 'm ago'
        newUsersList.appendChild(renderUser(u, false, label))
      }
      morph(usersList, newUsersList)
    }

    async function poll() {
      if (polling) return
      polling = true
      try {
        const res = await fetch('?api=messages&after=' + lastId)
        const data = await res.json()
        if (data.kicked) {
          if (data.error) alert(data.error)
          location.reload()
          return
        }
        if (data.messages.length) {
          for (const msg of data.messages) {
            const el = renderMessage(msg)
            if (el) messages.appendChild(el)
            lastId = msg.id
            if (!oldestId && msg.kind !== 'delete') oldestId = msg.id
          }
          if (atBottom) {
            scrollToBottom()
          } else {
            unread += data.messages.length
            badge.textContent = unread + ' new message' + (unread > 1 ? 's' : '')
            badge.style.display = 'block'
          }
        }
        renderUsers(data.users)
      } catch (e) {
        console.error(e)
      } finally {
        polling = false
      }
    }

    async function loadHistory() {
      if (!hasMore || !oldestId) return
      loadMore.textContent = 'Loading...'
      loadMore.disabled = true
      try {
        const res = await fetch('?api=history&before=' + oldestId)
        const data = await res.json()
        const scrollBefore = messages.scrollHeight
        let anchor = loadMore
        for (const msg of data.messages) {
          const el = renderMessage(msg)
          if (el) {
            anchor.after(el)
            anchor = el
          }
        }
        if (data.messages.length) oldestId = data.messages[0].id
        messages.scrollTop += messages.scrollHeight - scrollBefore
        hasMore = data.hasMore
        loadMore.style.display = hasMore ? '' : 'none'
      } catch (e) {
        console.error(e)
      }
      loadMore.textContent = 'Load older messages'
      loadMore.disabled = false
    }

    loadMore.addEventListener('click', loadHistory)

    form.addEventListener('submit', async (e) => {
      e.preventDefault()
      const txt = input.value.trim()
      if (!txt) return
      input.value = ''
      const body = new FormData()
      body.append('message', txt)
      body.append('csrf_token', csrfToken)
      if (replyTo) body.append('reply_to', replyTo)
      cancelReply()
      try {
        const res = await fetch('?api=send', {method: 'POST', body})
        const data = await res.json()
        if (data.error) addSystemMessage(data.error)
        await poll()
      } catch (e) {
        console.error(e)
      }
    })

    document.getElementById('file-input').addEventListener('change', async function () {
      const file = this.files[0]
      if (!file) return
      this.value = ''
      if (file.type !== 'image/gif') {
        addSystemMessage('Only GIF images are allowed.')
        return
      }
      if (file.size > 1024 * 1024) {
        addSystemMessage('File too large. Max 1MB.')
        return
      }
      const body = new FormData()
      body.append('image', file)
      body.append('csrf_token', csrfToken)
      if (replyTo) body.append('reply_to', replyTo)
      cancelReply()
      try {
        const res = await fetch('?api=send', {method: 'POST', body})
        const data = await res.json()
        if (data.error) addSystemMessage(data.error)
        await poll()
      } catch (e) {
        console.error(e)
      }
    })

    poll()
    setInterval(poll, 1000)

    function sendOffline() {
      const body = new FormData()
      body.append('csrf_token', csrfToken)
      navigator.sendBeacon('?api=offline', body)
    }

    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'hidden') sendOffline()
    })
    window.addEventListener('beforeunload', sendOffline)
