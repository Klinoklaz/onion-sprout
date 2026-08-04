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

    // deal with js remote import
    const rewriteJs = (js) => {
        const r = /\b(import\s*\(?|from)\s*(['"])\s*(https?:\/\/.*?)\2/g
        return js.replace(r, (match, s1, s2, s3) =>
            new URL(s3).hostname === myServerObj.hostname
                ? match : s1 + ' ' + s2 + MY_SERVER + '/' + s3 + s2)
    }

    const doRewrite = (node) => {
        if (!node instanceof Node) {
            return
        }
        // force script reload
        if (node.tagName === 'SCRIPT' && !node._altered) {
            const parent = node.parentElement
            parent.removeChild(node)
            const script = node.cloneNode(true)
            // prevent recursion in mu observer
            script._altered = true
            if (node.src) {
                script.src = rewrite(node.src)
            }
            script.textContent = rewriteJs(node.textContent)
            parent.appendChild(script)
        } else if (node.src) {
            node.src = rewrite(node.src)
        }
        // imageset
        if (node.tagName === 'IMG' && node.srcset) {
            const srcset = node.srcset.split(',')
            for (let i in srcset) {
                srcset[i] = rewrite(srcset[i].trim())
            }
            node.srcset = srcset.join(', ')
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

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[src],[href],[action],[formaction]')
            .forEach(doRewrite)
    })

    const realFetch = window.fetch
    window.fetch = async function (resource, options) {
        return await realFetch(rewrite(resource), options)
    }

    const realOpen = XMLHttpRequest.prototype.open
    XMLHttpRequest.prototype.open = function(method, url, ...args) {
        url = rewrite(url)
        return realOpen.apply(this, arguments)
    }

    if (!'MutationObserver' in globalThis) {
        return
    }
    // rewrite links in dynamic elements
    const observer = new MutationObserver((muList) => {
        for (const mutation of muList) {
            if (mutation.type === 'childList') {
                mutation.addedNodes.forEach(doRewrite)
            }
        }
    })
    observer.observe(document.documentElement, {childList: true, subtree: true})
})()