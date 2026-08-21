(() => {
    // wayback machine has its own rewriter, avoid conflict
    if (/(?:\.|^)archive.org$/.test(location.hostname)) {
        return
    }
    // macros encoded to circumvent detection by wm rewriter
    const _origin = atob(ORIGIN)
    const _myServer = atob(MY_SERVER)

    // deal with auto resolve in redirection
    if (!/^\/https?:\/\//.test(location.pathname)) {
        history.replaceState(null, '', _myServer + '/' + _origin
            + location.href.slice(location.origin.length))
    }
    // deal with abs path in history manipulation
    const isAbsPath = (url) => typeof url === 'string'
        && url.startsWith('/')
        && !/^\/https?:\/\//.test(url)
    const realPushState = history.pushState
    history.pushState = function(state, unused, url) {
        if (isAbsPath(url)) {
            url = '/' + _origin + url
        }
        realPushState.call(this, state, unused, url)
    }
    const realReplaceState = history.replaceState
    history.replaceState = function(state, unused, url) {
        if (isAbsPath(url)) {
            url = '/' + _origin + url
        }
        realReplaceState.call(this, state, unused, url)
    }

    const myServerObj = new URL(_myServer)

    const rewrite = (oldUrl) => {
        const url = String(oldUrl)
        if (url.startsWith('/')) {
            return _myServer + '/' + _origin + url
        }
        if (!URL.canParse(url)) {
            return oldUrl
        }
        const urlObj = new URL(url)
        if (['http:', 'https:'].includes(urlObj.protocol)
            && urlObj.hostname !== myServerObj.hostname) {
            return _myServer + '/' + url
        }
        // deal with auto resolve
        if (urlObj.hostname === myServerObj.hostname
            && !/^\/https?:\/\//.test(urlObj.pathname)) {
            return _myServer + '/' + _origin
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

    // deal with js remote import etc.
    const rewriteJs = (js) => {
        const r = /(['"`])\s*(https?:\/\/.*?)\1/g
        return js.replace(r, (match, s1, s2) =>
            new URL(s2).hostname === myServerObj.hostname
                ? match : s1 + _myServer + '/' + s2 + s1)
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
        window.addEventListener('message', e => {
            switch (e.data?.name) {
                case 'SUBFRAME_PRINT':
                    window.print()
                    break
                case 'SUBFRAME_RELOAD':
                    location.reload()
                    break
                case 'SUBFRAME_BACK':
                    history.back()
                    break
                case 'SUBFRAME_FORWARD':
                    history.forward()
                    break
            }
        })
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

    // deleting set-cookie header at server side isn't enough
    window.addEventListener('beforeunload', () => {
        const delList = document.cookie.split(';').map(
            item => item + '; Max-Age=60; Path=/')
        delList.forEach(item => {document.cookie = item})
    })
    document.currentScript?.remove() // prevent self conflict
})()