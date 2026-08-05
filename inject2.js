(() => {
    const myServerObj = new URL(MY_SERVER)
    const URL_ATTRS = new Set(['src', 'href', 'action', 'formaction', 'srcset', 'poster', 'data'])

    const rewrite = (oldUrl) => {
        const url = String(oldUrl)
        if (url.startsWith('/')) {
            return MY_SERVER + '/' + ORIGIN + url
        }
        if (!URL.canParse(url)) {
            return oldUrl
        }
        const urlObj = new URL(url)
        if (urlObj.protocol !== 'data:'
            && urlObj.protocol !== 'file:'
            && urlObj.hostname !== myServerObj.hostname) {
            return MY_SERVER + '/' + url
        }
        if (urlObj.protocol === 'data:'
            && urlObj.pathname.startsWith('text/javascript,')) {
            return encodeURI(rewriteJs(decodeURIComponent(url)))
        }
        return oldUrl
    }

    const rewriteSrcset = (value) => value.split(',').map(part => {
        const trimmed = part.trim()
        const m = trimmed.match(/^(\S+)(\s+.+)?$/)
        return m ? rewrite(m[1]) + (m[2] || '') : trimmed
    }).join(', ')

    const rewriteJs = (js) => {
        let out = js.replace(
            /(['"])\s*(https?:\/\/.*?)\1/g,
            (match, quote, target) =>
                new URL(target).hostname === myServerObj.hostname
                    ? match : quote + MY_SERVER + '/' + target + quote)
        out = out.replace(
            /^\s*import\s+(?:[\w*{}\s,]+\s+from\s+)?(['"])(https?:\/\/.*?)\1/gm,
            (match, quote, target) =>
                new URL(target).hostname === myServerObj.hostname
                    ? match : match.replace(target, MY_SERVER + '/' + target))
        return out
    }

    const hookUrlProperty = (proto, prop) => {
        const desc = Object.getOwnPropertyDescriptor(proto, prop)
        if (!desc?.set) {
            return
        }
        Object.defineProperty(proto, prop, {
            ...desc,
            set(value) {
                desc.set.call(this, prop === 'srcset' ? rewriteSrcset(value) : rewrite(value))
            },
        })
    }

    hookUrlProperty(HTMLScriptElement.prototype, 'src')
    hookUrlProperty(HTMLImageElement.prototype, 'src')
    hookUrlProperty(HTMLImageElement.prototype, 'srcset')
    hookUrlProperty(HTMLIFrameElement.prototype, 'src')
    hookUrlProperty(HTMLMediaElement.prototype, 'src')
    hookUrlProperty(HTMLAnchorElement.prototype, 'href')
    hookUrlProperty(HTMLLinkElement.prototype, 'href')
    hookUrlProperty(HTMLFormElement.prototype, 'action')

    const realSetAttribute = Element.prototype.setAttribute
    Element.prototype.setAttribute = function(name, value) {
        const key = name.toLowerCase()
        if (URL_ATTRS.has(key)) {
            value = key === 'srcset' ? rewriteSrcset(String(value)) : rewrite(value)
        }
        return realSetAttribute.call(this, name, value)
    }

    const realFetch = window.fetch.bind(window)
    window.fetch = function(resource, options) {
        if (resource instanceof Request) {
            const nextUrl = rewrite(resource.url)
            if (nextUrl !== resource.url) {
                resource = new Request(nextUrl, resource)
            }
        } else {
            resource = rewrite(resource)
        }
        return realFetch(resource, options)
    }

    const realOpen = XMLHttpRequest.prototype.open
    XMLHttpRequest.prototype.open = function(method, url, ...args) {
        return realOpen.call(this, method, rewrite(url), ...args)
    }

    if (navigator.sendBeacon) {
        const realSendBeacon = navigator.sendBeacon.bind(navigator)
        navigator.sendBeacon = function(url, data) {
            return realSendBeacon(rewrite(url), data)
        }
    }

    const RealWorker = window.Worker
    if (RealWorker) {
        window.Worker = function(url, options) {
            return new RealWorker(rewrite(url), options)
        }
        window.Worker.prototype = RealWorker.prototype
    }

    const RealWebSocket = window.WebSocket
    if (RealWebSocket) {
        window.WebSocket = function(url, protocols) {
            return protocols === undefined
                ? new RealWebSocket(rewrite(url))
                : new RealWebSocket(rewrite(url), protocols)
        }
        window.WebSocket.prototype = RealWebSocket.prototype
    }

    const reloadScript = (node) => {
        const parent = node.parentElement
        if (!parent) {
            return
        }
        parent.removeChild(node)
        const script = node.cloneNode(true)
        script._altered = true
        if (node.src) {
            script.src = rewrite(node.getAttribute('src') || node.src)
        }
        if (node.textContent) {
            script.textContent = rewriteJs(node.textContent)
        }
        parent.appendChild(script)
    }

    const doRewrite = (node) => {
        if (!(node instanceof Element)) {
            return
        }
        if (node.tagName === 'SCRIPT' && !node._altered) {
            reloadScript(node)
            return
        }
        if (node.src) {
            node.src = rewrite(node.src)
        }
        if (node.tagName === 'IMG' && node.srcset) {
            node.srcset = rewriteSrcset(node.srcset)
        }
        if (node.href) {
            node.href = rewrite(node.href)
        }
        if (node.action) {
            node.action = rewrite(node.action)
        }
        if (node.formAction) {
            node.formAction = rewrite(node.formAction)
        }
        if (node.poster) {
            node.poster = rewrite(node.poster)
        }
    }

    const rewriteSubtree = (root) => {
        if (!(root instanceof Element)) {
            return
        }
        doRewrite(root)
        root.querySelectorAll('[src],[href],[action],[formaction],[srcset],[poster]')
            .forEach(doRewrite)
    }

    const rewriteAll = () => {
        rewriteSubtree(document.documentElement)
    }

    rewriteAll()
    document.addEventListener('DOMContentLoaded', rewriteAll)

    if (!('MutationObserver' in globalThis)) {
        return
    }

    const observer = new MutationObserver((muList) => {
        for (const mutation of muList) {
            if (mutation.type === 'childList') {
                mutation.addedNodes.forEach(item => rewriteSubtree(item))
                continue
            }
            if (mutation.type !== 'attributes') {
                continue
            }
            const attr = mutation.attributeName
            if (!attr || !URL_ATTRS.has(attr.toLowerCase())) {
                continue
            }
            const el = mutation.target
            if (!(el instanceof Element)) {
                continue
            }
            const raw = el.getAttribute(attr)
            if (!raw) {
                continue
            }
            const next = attr.toLowerCase() === 'srcset'
                ? rewriteSrcset(raw)
                : rewrite(raw)
            if (next !== raw) {
                realSetAttribute.call(el, attr, next)
            }
        }
    })

    observer.observe(document.documentElement, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: [...URL_ATTRS],
    })
})()
