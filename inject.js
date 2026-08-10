(() => {
    const myServerObj = new URL(MY_SERVER)

    const rewrite = (oldUrl) => {
        const url = String(oldUrl)
        if (url.startsWith('/')) {
            return MY_SERVER + '/' + ORIGIN + url
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

    // force script reload
    const reloadJs = (js) => {
        if (!(js instanceof HTMLScriptElement) || js._reloaded) {
            return
        }
        const parent = js.parentElement
        parent?.removeChild(js)
        js = js.cloneNode(true)
        // prevent recursion in mu observer
        js._reloaded = true
        if (js.src) {
            js.src = rewrite(js.src)
        }
        js.textContent = rewriteJs(js.textContent)
        parent?.appendChild(js)
    }

    const alterElement = (e) => {
        if (!(e instanceof Element)) {
            return
        }
        if (e.tagName === 'SCRIPT') {
            reloadJs(e)
        } else if (e.src) {
            e.src = rewrite(e.src)
        }
        // imageset
        if (e.tagName === 'IMG' && e.srcset) {
            e.srcset = rewriteImgSet(e.srcset)
        }
        if (e.href) {
            e.href = rewrite(e.href)
        }
        // form
        if (e.action) {
            e.action = rewrite(e.action)
        }
        // button, input
        if (e.formaction) {
            e.formaction = rewrite(e.formaction)
        }
    }

    const attrSelector = urlAttr.map(a => '[' + a + ']').join(',')
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll(attrSelector).forEach(alterElement)
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

    // hack: fix that mutation event returns script element
    // with incomplete text content
    // @see https://github.com/whatwg/dom/issues/1116
    // @see https://jsfiddle.net/64cgrmz5/1/
    const pendingScripts = []
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
                if (item instanceof HTMLScriptElement) {
                    pendingScripts.push(item)
                    return
                }
                while (pendingScripts.length) {
                    reloadJs(pendingScripts.shift())
                }
                alterElement(item)
                item.querySelectorAll(attrSelector).forEach(alterElement)
            })
        }
    })
    observer.observe(document.documentElement, {childList: true, subtree: true})
})()