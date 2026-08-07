(() => {
    const myServerObj = new URL(MY_SERVER)

    const rewrite = (oldUrl) => {
        const url = String(oldUrl)
        if (url.startsWith('/')) {
            return '/' + ORIGIN + url
        }
        if (!URL.canParse(url)) {
            return oldUrl
        }
        const urlObj = new URL(url)
        if (['http:', 'https:'].includes(urlObj.protocol)
            && urlObj.hostname !== myServerObj.hostname) {
            return MY_SERVER + '/' + url
        }
        // deal with auto resolve
        if (urlObj.hostname === myServerObj.hostname
            && !/^\/https?:\/\//.test(urlObj.pathname)) {
            return MY_SERVER + '/' + ORIGIN
                + url.slice(urlObj.origin.length)
        }
        if (urlObj.protocol === 'data:'
            && urlObj.pathname.startsWith('text/javascript,')) {
            return encodeURI(rewriteJs(decodeURIComponent(url)))
        }
        return oldUrl
    }

    // intercept common network call
    const realFetch = window.fetch
    window.fetch = async function (resource, options) {
        if (resource instanceof Request) {
            const newUrl = rewrite(resource.url)
            if (newUrl !== resource.url) {
                resource = new Request(newUrl, resource)
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
    /*
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
    */

    // deal with js remote import etc.
    const rewriteJs = (js) => {
        const r = /(['"])\s*(https?:\/\/.*?)\1/g
        return js.replace(r, (match, s1, s2) =>
            new URL(s2).hostname === myServerObj.hostname
                ? match : s1 + MY_SERVER + '/' + s2 + s1)
    }

    // imageset
    const rewriteImgSet = (srcset) => srcset &&
        srcset.split(',').map(src => rewrite(src.trim())).join(', ')

    // intercept `setAttribute('src', url)`
    const urlAttr = ['src', 'href', 'action', 'formaction', 'srcset', 'poster']
    const realSetAttribute = Element.prototype.setAttribute
    Element.prototype.setAttribute = function(name, value) {
        name = name.toLowerCase()
        if (name === 'srcset') {
            value = rewriteImgSet(String(value))
        } else if (urlAttr.includes(name)) {
            value = rewrite(value)
        }
        return realSetAttribute.call(this, name, value)
    }
    // intercept `element.src = url`
    const hookUrlProperty = (proto, prop) => {
        const desc = Object.getOwnPropertyDescriptor(proto, prop)
        const rewriter = prop === 'srcset' ? rewriteImgSet : rewrite
        Object.defineProperty(proto, prop, {
            ...desc,
            set(value) {
                desc.set.call(this, rewriter(value))
            },
        })
    }
    hookUrlProperty(HTMLScriptElement.prototype, 'src')
    hookUrlProperty(HTMLImageElement.prototype, 'src')
    hookUrlProperty(HTMLImageElement.prototype, 'srcset')
    hookUrlProperty(HTMLIFrameElement.prototype, 'src')
    hookUrlProperty(HTMLMediaElement.prototype, 'src')
    hookUrlProperty(HTMLVideoElement.prototype, 'poster')
    hookUrlProperty(HTMLAnchorElement.prototype, 'href')
    hookUrlProperty(HTMLLinkElement.prototype, 'href')
    hookUrlProperty(HTMLFormElement.prototype, 'action')
    hookUrlProperty(HTMLInputElement.prototype, 'formaction')
    hookUrlProperty(HTMLButtonElement.prototype, 'formaction')

    const doRewrite = (node) => {
        if (!(node instanceof Element)) {
            return
        }
        // force script reload
        if (node.tagName === 'SCRIPT' && !node._altered) {
            const parent = node.parentElement
            parent?.removeChild(node)
            node = node.cloneNode(true)
            // prevent recursion in mu observer
            node._altered = true
            if (node.src) {
                node.src = rewrite(node.src)
            }
            node.textContent = rewriteJs(node.textContent)
            parent?.appendChild(node)
        } else if (node.src) {
            node.src = rewrite(node.src)
        }
        // imageset
        if (node.tagName === 'IMG' && node.srcset) {
            node.srcset = rewriteImgSet(node.srcset)
        }
        if (node.href) {
            node.href = rewrite(node.href)
        }
        // form
        if (node.action) {
            node.action = rewrite(node.action)
        }
        // button, input
        if (node.formaction) {
            node.formaction = rewrite(node.formaction)
        }
    }

    const attrSelector = urlAttr.map(a => '[' + a + ']').join(',')
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll(attrSelector).forEach(doRewrite)
        // for iframe host page
        if (window.parent === window) {
            return
        }
        window.parent.postMessage({
            name: 'SUBFRAME_INFO',
            title: document.querySelector('title')?.innerText,
            height: document.documentElement.scrollHeight,
            favicon: document.querySelector('link[rel*="icon"]')?.href
        }, '*')
    })

    // rewrite links in dynamic elements
    const observer = new MutationObserver((muList) => {
        for (const mutation of muList) {
            if (mutation.type !== 'childList') {
                continue
            }
            mutation.addedNodes.forEach(item => {
                if (!(item instanceof Element)) {
                    return
                }
                doRewrite(item)
                item.querySelectorAll(attrSelector).forEach(doRewrite)
            })
        }
    })
    observer.observe(document.documentElement, {childList: true, subtree: true})
})()